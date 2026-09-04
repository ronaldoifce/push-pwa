<?php

namespace campus\push;

/**
 * Fila de notificações de um sistema. Guarda só o que é comum a qualquer aviso
 * — quem recebe, o que aparece na tela, quando sai e o que já foi tentado.
 *
 * O que liga a notificação ao registro de origem é responsabilidade do sistema:
 * `referencia` serve para um ponteiro solto, e `extras` permite gravar a coluna
 * com chave estrangeira de verdade para o registro que originou o aviso, que é
 * o motivo de cada aplicação manter a tabela no seu próprio banco.
 */
class Fila
{
    const PENDENTE = 'P';
    const PROCESSANDO = 'R';
    const ENVIADA = 'E';
    const FALHOU = 'F';
    const CANCELADA = 'C';
    const MAX_TENTATIVAS = 5;
    /** Minutos até uma nova tentativa, e até destravar um item preso em 'R'. */
    const INTERVALO_TENTATIVA = 15;

    /** @var Configuracao */
    private $config;

    public function __construct(Configuracao $config)
    {
        $this->config = $config;
    }

    /**
     * Enfileira um aviso. `chave_unica` torna a chamada idempotente: repetir o
     * mesmo gatilho não gera um segundo aviso, o que importa porque o gatilho
     * costuma estar no meio de um fluxo que pode ser reenviado.
     *
     * @param array $aviso chave_unica, cpf, titulo, mensagem, destino e,
     *                     opcionalmente, tag, enviar_em, referencia, extras.
     * @return bool false quando o aviso já existia.
     */
    public function enfileirar(array $aviso)
    {
        foreach (array('chave_unica', 'cpf', 'titulo', 'mensagem', 'destino') as $obrigatorio) {
            if (!isset($aviso[$obrigatorio]) || trim((string)$aviso[$obrigatorio]) === '') {
                throw new \InvalidArgumentException('Notificação sem "' . $obrigatorio . '".');
            }
        }

        $colunas = array(
            'chave_unica' => mb_substr((string)$aviso['chave_unica'], 0, 100, 'UTF-8'),
            'cpf' => (string)$aviso['cpf'],
            'titulo' => mb_substr((string)$aviso['titulo'], 0, 150, 'UTF-8'),
            'mensagem' => mb_substr((string)$aviso['mensagem'], 0, 255, 'UTF-8'),
            'destino' => mb_substr((string)$aviso['destino'], 0, 255, 'UTF-8'),
            'tag' => mb_substr((string)(isset($aviso['tag']) ? $aviso['tag'] : $this->config->sistema()), 0, 100, 'UTF-8'),
            'referencia' => mb_substr((string)(isset($aviso['referencia']) ? $aviso['referencia'] : ''), 0, 100, 'UTF-8'),
            'enviar_em' => $this->momento(isset($aviso['enviar_em']) ? $aviso['enviar_em'] : 'now')
        );

        if (isset($aviso['extras']) && is_array($aviso['extras'])) {
            foreach ($aviso['extras'] as $coluna => $valor) {
                if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', (string)$coluna) !== 1) {
                    throw new \InvalidArgumentException('Coluna extra inválida na notificação.');
                }
                if (isset($colunas[$coluna])) {
                    throw new \InvalidArgumentException('Coluna extra sobrescreve campo da fila: ' . $coluna . '.');
                }
                $colunas[$coluna] = $valor;
            }
        }

        $nomes = array_keys($colunas);
        $sql = 'INSERT IGNORE INTO ' . $this->config->tabelaNotificacao()
            . ' (' . implode(', ', $nomes) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($nomes), '?')) . ')';
        $stmt = $this->config->pdo()->prepare($sql);
        if (!$stmt || !$stmt->execute(array_values($colunas))) {
            return false;
        }
        // INSERT IGNORE engole o erro da chave duplicada, então o que distingue
        // "já existia" de "falhou de verdade" é o errorInfo, não o retorno.
        $erro = $stmt->errorInfo();
        if (!empty($erro[2])) {
            return false;
        }
        return $stmt->rowCount() === 1;
    }

    /**
     * Cancela avisos ainda não enviados de um registro que deixou de existir ou
     * de valer. É o par de `enfileirar`: como a fila fica no banco do próprio
     * sistema, quem apaga o registro de origem é quem sabe cancelar o aviso.
     */
    public function cancelarPorReferencia($referencia)
    {
        $stmt = $this->config->pdo()->prepare(
            'UPDATE ' . $this->config->tabelaNotificacao() . '
             SET status = ? WHERE referencia = ? AND status IN (?, ?)'
        );
        if (!$stmt || !$stmt->execute(array(self::CANCELADA, (string)$referencia, self::PENDENTE, self::PROCESSANDO))) {
            throw new \RuntimeException('Não foi possível cancelar as notificações do registro.');
        }
        return $stmt->rowCount();
    }

    /**
     * Reserva um lote para envio, marcando cada item como em processamento
     * dentro da mesma transação. Um item preso em 'R' — processo derrubado no
     * meio — volta a ser elegível depois do intervalo de tentativa.
     */
    public function reservarLote($limite)
    {
        $limite = max(1, min(50, (int)$limite));
        $tabela = $this->config->tabelaNotificacao();
        $db = $this->config->pdo();
        $db->beginTransaction();
        try {
            $sql = 'SELECT * FROM ' . $tabela . '
                    WHERE tentativas < ?
                      AND enviar_em <= NOW()
                      AND proxima_tentativa <= NOW()
                      AND (status = ? OR (status = ? AND atualizado_em < DATE_SUB(NOW(), INTERVAL '
                        . self::INTERVALO_TENTATIVA . ' MINUTE)))
                    ORDER BY enviar_em, codigo
                    LIMIT ' . $limite . ' FOR UPDATE';
            $stmt = $db->prepare($sql);
            if (!$stmt || !$stmt->execute(array(self::MAX_TENTATIVAS, self::PENDENTE, self::PROCESSANDO))) {
                throw new \RuntimeException('Não foi possível reservar a fila de notificações.');
            }
            $itens = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if ($itens) {
                $codigos = array_map('intval', array_column($itens, 'codigo'));
                $atualizadas = $db->exec('UPDATE ' . $tabela . " SET status = '" . self::PROCESSANDO
                    . "', atualizado_em = NOW()"
                    . ' WHERE codigo IN (' . implode(',', $codigos) . ')');
                if ($atualizadas === false || $atualizadas !== count($codigos)) {
                    throw new \RuntimeException('Não foi possível bloquear todas as notificações reservadas.');
                }
            }
            if (!$db->commit()) {
                throw new \RuntimeException('Não foi possível confirmar a reserva da fila de notificações.');
            }
            return $itens;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function marcarEnviada($codigo)
    {
        $stmt = $this->config->pdo()->prepare(
            'UPDATE ' . $this->config->tabelaNotificacao() . '
             SET status = ?, enviado_em = NOW(), ultimo_erro = NULL WHERE codigo = ?'
        );
        if (!$stmt || !$stmt->execute(array(self::ENVIADA, $codigo)) || $stmt->rowCount() !== 1) {
            throw new \RuntimeException('Não foi possível confirmar o envio da notificação.');
        }
    }

    public function marcarCancelada($codigo, $motivo = '')
    {
        $stmt = $this->config->pdo()->prepare(
            'UPDATE ' . $this->config->tabelaNotificacao() . '
             SET status = ?, ultimo_erro = ? WHERE codigo = ?'
        );
        if (!$stmt || !$stmt->execute(array(
            self::CANCELADA, mb_substr((string)$motivo, 0, 500, 'UTF-8'), $codigo
        ))) {
            throw new \RuntimeException('Não foi possível cancelar a notificação.');
        }
    }

    public function marcarFalha($codigo, $erro)
    {
        $stmt = $this->config->pdo()->prepare(
            'UPDATE ' . $this->config->tabelaNotificacao() . '
             SET status = IF(tentativas + 1 >= ?, ?, ?),
                 tentativas = tentativas + 1,
                 proxima_tentativa = DATE_ADD(NOW(), INTERVAL ' . self::INTERVALO_TENTATIVA . ' MINUTE),
                 ultimo_erro = ?
             WHERE codigo = ?'
        );
        if (!$stmt || !$stmt->execute(array(
            self::MAX_TENTATIVAS,
            self::FALHOU,
            self::PENDENTE,
            mb_substr((string)$erro, 0, 500, 'UTF-8'),
            $codigo
        )) || $stmt->rowCount() !== 1) {
            throw new \RuntimeException('Não foi possível registrar a falha da notificação.');
        }
    }

    /**
     * Aceita `now`, um `DateTimeInterface` ou qualquer texto que o PHP entenda,
     * e devolve sempre no formato que o MySQL grava.
     */
    private function momento($valor)
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d H:i:s');
        }
        $texto = trim((string)$valor);
        if ($texto === '' || strtolower($texto) === 'now') {
            return date('Y-m-d H:i:s');
        }
        $data = date_create($texto);
        if (!$data) {
            throw new \InvalidArgumentException('Data de envio inválida na notificação.');
        }
        return $data->format('Y-m-d H:i:s');
    }
}
