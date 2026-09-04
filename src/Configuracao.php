<?php

namespace campus\push;

/**
 * Tudo que muda de um sistema para outro. O restante do pacote não lê variável
 * de ambiente nem constante global: quem instala decide de onde vem cada valor,
 * porque cada aplicação que instala o pacote guarda a configuração de um
 * jeito — arquivo de ambiente, arquivo de settings, banco.
 */
class Configuracao
{
    /** @var \PDO */
    private $pdo;
    private $sistema;
    private $vapidPublica;
    private $vapidPrivada;
    private $vapidAssunto;
    private $tabelaAssinatura;
    private $tabelaNotificacao;
    private $limiteAtivas;
    private $diasHigienizacao;

    /**
     * @param array $opcoes pdo, sistema, vapid_publica, vapid_privada e,
     *                      opcionalmente, vapid_assunto, tabela_assinatura,
     *                      tabela_notificacao, limite_ativas, dias_higienizacao.
     */
    public function __construct(array $opcoes)
    {
        foreach (array('pdo', 'sistema', 'vapid_publica', 'vapid_privada', 'vapid_assunto') as $obrigatorio) {
            if (!isset($opcoes[$obrigatorio]) || $opcoes[$obrigatorio] === '') {
                throw new \InvalidArgumentException('Configuração de push sem "' . $obrigatorio . '".');
            }
        }
        if (!$opcoes['pdo'] instanceof \PDO) {
            throw new \InvalidArgumentException('A configuração de push espera uma conexão PDO.');
        }

        $this->pdo = $opcoes['pdo'];
        $this->sistema = self::validarIdentificador($opcoes['sistema'], 'sistema');
        $this->vapidPublica = trim((string)$opcoes['vapid_publica']);
        $this->vapidPrivada = trim((string)$opcoes['vapid_privada']);
        // Sem valor padrão de propósito: o assunto VAPID identifica quem envia
        // para o serviço de push, e um padrão embutido faria uma instalação
        // se apresentar como outra.
        $this->vapidAssunto = (string)$opcoes['vapid_assunto'];
        // Os nomes de tabela entram em SQL por interpolação — não existe
        // placeholder para identificador — então são validados aqui, uma vez.
        $this->tabelaAssinatura = self::validarIdentificador(
            isset($opcoes['tabela_assinatura']) ? $opcoes['tabela_assinatura'] : 'push_assinatura',
            'tabela_assinatura'
        );
        $this->tabelaNotificacao = self::validarIdentificador(
            isset($opcoes['tabela_notificacao']) ? $opcoes['tabela_notificacao'] : 'push_notificacao',
            'tabela_notificacao'
        );
        $this->limiteAtivas = isset($opcoes['limite_ativas']) ? (int)$opcoes['limite_ativas'] : 5;
        $this->limiteAtivas = max(1, min(20, $this->limiteAtivas));
        $this->diasHigienizacao = isset($opcoes['dias_higienizacao']) ? (int)$opcoes['dias_higienizacao'] : 90;
        $this->diasHigienizacao = max(30, $this->diasHigienizacao);
    }

    public function pdo()
    {
        return $this->pdo;
    }

    public function sistema()
    {
        return $this->sistema;
    }

    public function vapidPublica()
    {
        return $this->vapidPublica;
    }

    public function vapidPrivada()
    {
        return $this->vapidPrivada;
    }

    public function vapidAssunto()
    {
        return $this->vapidAssunto;
    }

    public function tabelaAssinatura()
    {
        return $this->tabelaAssinatura;
    }

    public function tabelaNotificacao()
    {
        return $this->tabelaNotificacao;
    }

    public function limiteAtivas()
    {
        return $this->limiteAtivas;
    }

    public function diasHigienizacao()
    {
        return $this->diasHigienizacao;
    }

    public function exigirVapid()
    {
        if ($this->vapidPublica === '' || $this->vapidPrivada === '') {
            throw new \RuntimeException('Chaves VAPID não configuradas.');
        }
    }

    private static function validarIdentificador($valor, $campo)
    {
        $valor = trim((string)$valor);
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $valor) !== 1) {
            throw new \InvalidArgumentException('Valor inválido para "' . $campo . '".');
        }
        return $valor;
    }
}
