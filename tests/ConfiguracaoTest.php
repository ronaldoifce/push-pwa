<?php

namespace pushpwa\tests;

use pushpwa\Configuracao;
use PHPUnit\Framework\TestCase;

class ConfiguracaoTest extends TestCase
{
    public function testExigeConexaoSistemaEChaves()
    {
        foreach (array('pdo', 'sistema', 'vapid_publica', 'vapid_privada', 'vapid_assunto') as $faltando) {
            $opcoes = array(
                'pdo' => new \PDO('sqlite::memory:'),
                'sistema' => 'minha_app',
                'vapid_publica' => 'publica',
                'vapid_privada' => 'privada',
                'vapid_assunto' => 'https://exemplo.invalido'
            );
            unset($opcoes[$faltando]);
            try {
                new Configuracao($opcoes);
                $this->fail('Aceitou configuração sem "' . $faltando . '".');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($faltando, $e->getMessage());
            }
        }
    }

    /**
     * Nome de tabela entra em SQL por interpolação — não existe placeholder para
     * identificador — então a validação aqui é a única barreira.
     */
    public function testRecusaNomeDeTabelaQueNaoSejaIdentificador()
    {
        $perigosos = array(
            'push_assinatura; DROP TABLE pessoa',
            'push assinatura',
            '1_push',
            'push`assinatura',
            str_repeat('a', 65)
        );
        foreach ($perigosos as $nome) {
            try {
                Auxiliar::configuracao(array('tabela_assinatura' => $nome));
                $this->fail('Aceitou nome de tabela inválido: ' . $nome);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('tabela_assinatura', $e->getMessage());
            }
        }
    }

    public function testPadroesELimites()
    {
        $config = Auxiliar::configuracao();

        $this->assertSame('push_assinatura', $config->tabelaAssinatura());
        $this->assertSame('push_notificacao', $config->tabelaNotificacao());
        $this->assertSame(5, $config->limiteAtivas());
        $this->assertSame(90, $config->diasHigienizacao());
        $this->assertSame('https://exemplo.invalido', $config->vapidAssunto());

        // O teto e a janela de higienização têm piso: uma configuração distraída
        // não pode desligar a proteção nem apagar assinaturas ainda em uso.
        $curto = Auxiliar::configuracao(array('limite_ativas' => 0, 'dias_higienizacao' => 1));
        $this->assertSame(1, $curto->limiteAtivas());
        $this->assertSame(30, $curto->diasHigienizacao());
    }

    public function testExigirVapidReclamaQuandoAsChavesEstaoVazias()
    {
        $config = Auxiliar::configuracao();
        $config->exigirVapid();

        $this->expectException(\InvalidArgumentException::class);
        Auxiliar::configuracao(array('vapid_privada' => ''));
    }
}
