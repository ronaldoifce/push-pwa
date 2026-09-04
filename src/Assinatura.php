<?php

namespace campus\push;

/**
 * Assinaturas de notificação de um sistema. Cada sistema tem a sua própria
 * tabela, no seu próprio banco, com as chaves estrangeiras que fizerem sentido
 * ali — o que é compartilhado é esta lógica, não as linhas.
 *
 * O endpoint continua sendo a chave única, mas a deduplicação real é pelo
 * `dispositivo`: o endpoint do serviço de push é reciclado sozinho (limpeza de
 * dados do site, reinstalação do PWA, redefinição da permissão, novo registro do
 * navegador) e, sem um identificador estável do aparelho, cada rotação criava
 * uma linha nova e a anterior ficava ativa para sempre — uma notificação por
 * linha, para a mesma pessoa.
 */
class Assinatura
{
    const ATIVA = 'A';
    const INATIVA = 'I';

    /** @var Configuracao */
    private $config;

    public function __construct(Configuracao $config)
    {
        $this->config = $config;
    }

    public function salvar($cpf, array $dados)
    {
        $endpoint = isset($dados['endpoint']) ? trim((string)$dados['endpoint']) : '';
        $chaves = isset($dados['keys']) && is_array($dados['keys']) ? $dados['keys'] : array();
        $publica = isset($chaves['p256dh']) ? trim((string)$chaves['p256dh']) : '';
        $auth = isset($chaves['auth']) ? trim((string)$chaves['auth']) : '';
        $codificacao = isset($dados['contentEncoding']) ? (string)$dados['contentEncoding'] : 'aes128gcm';
        $dispositivo = isset($dados['dispositivo']) ? trim((string)$dados['dispositivo']) : '';

        if (!self::endpointValido($endpoint)
            || !self::chaveValida($publica, 60, 255)
            || !self::chaveValida($auth, 16, 255)
            || !in_array($codificacao, array('aes128gcm', 'aesgcm'), true)) {
            throw new \InvalidArgumentException('Assinatura de notificações inválida.');
        }
        if (!self::dispositivoValido($dispositivo)) {
            // Navegador antigo ou identificador corrompido: grava sem dispositivo
            // em vez de recusar o alerta. A linha entra no teto por pessoa do
            // mesmo jeito, então ela não volta a acumular.
            $dispositivo = '';
        }

        $db = $this->config->pdo();
        $db->beginTransaction();
        try {
            $this->gravar($cpf, $dispositivo, $endpoint, $publica, $auth, $codificacao);
            $this->aposentarAssinaturasDoMesmoAparelho($cpf, $dispositivo, $endpoint);
            $this->aplicarLimitePorPessoa($cpf, $endpoint);
            $db->commit();
        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
        return true;
    }

    /**
     * Grava (ou atualiza) a assinatura deste endpoint. Reenviar a mesma
     * assinatura apenas renova o `visto_em`, que é o que mantém um aparelho em
     * uso fora da higienização.
     */
    private function gravar($cpf, $dispositivo, $endpoint, $publica, $auth, $codificacao)
    {
        $sql = 'INSERT INTO ' . $this->config->tabelaAssinatura() . '
                (cpf, dispositivo, endpoint, endpoint_hash, chave_publica, chave_auth, codificacao, status, visto_em)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                  cpf = VALUES(cpf), dispositivo = VALUES(dispositivo),
                  endpoint = VALUES(endpoint),
                  chave_publica = VALUES(chave_publica), chave_auth = VALUES(chave_auth),
                  codificacao = VALUES(codificacao), status = VALUES(status),
                  visto_em = VALUES(visto_em)';
        $stmt = $this->config->pdo()->prepare($sql);
        if (!$stmt || !$stmt->execute(array(
            $cpf, $dispositivo, $endpoint, self::hash($endpoint), $publica, $auth, $codificacao, self::ATIVA
        ))) {
            throw new \RuntimeException('Não foi possível ativar as notificações.');
        }
    }

    /**
     * Um aparelho só precisa de uma assinatura ativa. Quando o navegador recicla
     * o endpoint, a linha anterior ficava ativa para sempre e a notificação saía
     * duplicada; aqui ela é desativada assim que o mesmo aparelho reaparece.
     *
     * Linhas gravadas antes de existir a coluna `dispositivo` não têm como ser
     * ligadas ao aparelho, então são aposentadas pelo serviço de push do
     * endpoint: se o palpite errar e atingir outro aparelho real da mesma
     * pessoa, ele se reassina sozinho na próxima abertura do sistema.
     */
    private function aposentarAssinaturasDoMesmoAparelho($cpf, $dispositivo, $endpoint)
    {
        $condicoes = array();
        $valores = array(self::INATIVA, $cpf, self::ATIVA, self::hash($endpoint));

        if ($dispositivo !== '') {
            $condicoes[] = 'dispositivo = ?';
            $valores[] = $dispositivo;
        }
        $servico = self::servicoDoEndpoint($endpoint);
        if ($servico !== '') {
            $condicoes[] = "(dispositivo = '' AND endpoint LIKE ?)";
            $valores[] = self::escaparLike($servico) . '%';
        }
        if (!$condicoes) {
            return;
        }

        $stmt = $this->config->pdo()->prepare(
            'UPDATE ' . $this->config->tabelaAssinatura() . ' SET status = ?
             WHERE cpf = ? AND status = ? AND endpoint_hash <> ?
               AND (' . implode(' OR ', $condicoes) . ')'
        );
        if (!$stmt || !$stmt->execute($valores)) {
            throw new \RuntimeException('Não foi possível organizar os dispositivos de notificação.');
        }
    }

    /**
     * Rede de segurança para o caso de um aparelho trocar de identificador:
     * mantém apenas as assinaturas ativas mais recentes, sempre preservando a
     * que acabou de ser confirmada.
     */
    private function aplicarLimitePorPessoa($cpf, $endpoint)
    {
        $stmt = $this->config->pdo()->prepare(
            'SELECT codigo FROM ' . $this->config->tabelaAssinatura() . '
             WHERE cpf = ? AND status = ? AND endpoint_hash <> ?
             ORDER BY visto_em DESC, codigo DESC'
        );
        if (!$stmt || !$stmt->execute(array($cpf, self::ATIVA, self::hash($endpoint)))) {
            throw new \RuntimeException('Não foi possível consultar os dispositivos de notificação.');
        }
        // A assinatura recém-confirmada já ocupa uma das vagas do teto.
        $excedentes = array_slice($stmt->fetchAll(\PDO::FETCH_COLUMN, 0), $this->config->limiteAtivas() - 1);
        if (!$excedentes) {
            return;
        }

        $marcadores = implode(',', array_fill(0, count($excedentes), '?'));
        $stmt = $this->config->pdo()->prepare(
            'UPDATE ' . $this->config->tabelaAssinatura() . ' SET status = ? WHERE codigo IN (' . $marcadores . ')'
        );
        if (!$stmt || !$stmt->execute(array_merge(array(self::INATIVA), $excedentes))) {
            throw new \RuntimeException('Não foi possível organizar os dispositivos de notificação.');
        }
    }

    public function getAtivasByCpf($cpf)
    {
        $stmt = $this->config->pdo()->prepare(
            'SELECT * FROM ' . $this->config->tabelaAssinatura() . '
             WHERE cpf = ? AND status = ? ORDER BY codigo'
        );
        if (!$stmt || !$stmt->execute(array($cpf, self::ATIVA))) {
            throw new \RuntimeException('Não foi possível consultar os dispositivos de notificação.');
        }
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Usado para não pedir a permissão de novo quando a pessoa já tem assinatura
     * ativa salva. Todos os sistemas do campus ficam na mesma origem, e o
     * navegador às vezes relê `Notification.permission` como não concedida ao
     * alternar entre ícones instalados separadamente.
     */
    public function existeAtiva($cpf)
    {
        $stmt = $this->config->pdo()->prepare(
            'SELECT 1 FROM ' . $this->config->tabelaAssinatura() . '
             WHERE cpf = ? AND status = ? LIMIT 1'
        );
        if (!$stmt || !$stmt->execute(array($cpf, self::ATIVA))) {
            throw new \RuntimeException('Não foi possível consultar os dispositivos de notificação.');
        }
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Desativa um endpoint recusado pelo serviço de push (404/410). Sem CPF de
     * propósito: quem chama é o processador, a partir da resposta do provedor.
     */
    public function desativarPorEndpoint($endpoint)
    {
        $stmt = $this->config->pdo()->prepare(
            'UPDATE ' . $this->config->tabelaAssinatura() . ' SET status = ? WHERE endpoint_hash = ?'
        );
        if (!$stmt || !$stmt->execute(array(self::INATIVA, self::hash($endpoint)))) {
            throw new \RuntimeException('Não foi possível desativar o dispositivo de notificação.');
        }
        return true;
    }

    /**
     * Desativa um endpoint informado pelo próprio navegador do usuário. O CPF
     * entra na condição para que uma sessão não consiga desligar o alerta de
     * outra pessoa caso descubra o endpoint dela.
     */
    public function desativarDoUsuario($cpf, $endpoint)
    {
        $stmt = $this->config->pdo()->prepare(
            'UPDATE ' . $this->config->tabelaAssinatura() . ' SET status = ? WHERE cpf = ? AND endpoint_hash = ?'
        );
        if (!$stmt || !$stmt->execute(array(self::INATIVA, $cpf, self::hash($endpoint)))) {
            throw new \RuntimeException('Não foi possível desativar o dispositivo de notificação.');
        }
        return true;
    }

    /**
     * Aposenta assinaturas que nenhum aparelho reconfirma há muito tempo. Um
     * aparelho em uso renova o `visto_em` a cada abertura do sistema; passar de
     * meses sem sinal significa que o endpoint já foi reciclado pelo navegador e
     * que continuar enviando para ele só produz falha.
     */
    public function higienizar()
    {
        // Interpolado, e não vinculado: o valor já é inteiro e limitado na
        // configuração, e MySQL não aceita placeholder em INTERVAL com prepare
        // nativo.
        $dias = $this->config->diasHigienizacao();
        $stmt = $this->config->pdo()->prepare(
            'UPDATE ' . $this->config->tabelaAssinatura() . ' SET status = ?
             WHERE status = ? AND visto_em < (NOW() - INTERVAL ' . $dias . ' DAY)'
        );
        if (!$stmt || !$stmt->execute(array(self::INATIVA, self::ATIVA))) {
            throw new \RuntimeException('Não foi possível limpar os dispositivos de notificação.');
        }
        return $stmt->rowCount();
    }

    private static function hash($endpoint)
    {
        return hash('sha256', (string)$endpoint);
    }

    /**
     * Prefixo `https://servidor/` do endpoint, usado para reconhecer o serviço
     * de push (FCM, Mozilla, WNS) das assinaturas antigas, gravadas sem
     * dispositivo.
     */
    private static function servicoDoEndpoint($endpoint)
    {
        $host = parse_url($endpoint, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }
        $porta = parse_url($endpoint, PHP_URL_PORT);
        return 'https://' . $host . ($porta ? ':' . (int)$porta : '') . '/';
    }

    /**
     * O host do endpoint entra em um LIKE. Sem escapar, um `_` no nome do
     * serviço de push casaria com qualquer caractere e desativaria assinaturas
     * de outro serviço.
     */
    private static function escaparLike($texto)
    {
        return str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $texto);
    }

    private static function endpointValido($endpoint)
    {
        if (strlen($endpoint) < 20 || strlen($endpoint) > 1000 || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return false;
        }
        return strtolower((string)parse_url($endpoint, PHP_URL_SCHEME)) === 'https';
    }

    private static function chaveValida($chave, $minimo, $maximo)
    {
        $tamanho = strlen($chave);
        return $tamanho >= $minimo && $tamanho <= $maximo
            && preg_match('/^[A-Za-z0-9_\-+=\/]+$/', $chave) === 1;
    }

    private static function dispositivoValido($dispositivo)
    {
        return preg_match('/^[A-Za-z0-9\-]{8,64}$/', $dispositivo) === 1;
    }
}
