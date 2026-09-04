<?php

namespace pushpwa\tests;

use pushpwa\Assinatura;
use PHPUnit\Framework\TestCase;

/**
 * A validação acontece antes de qualquer SQL, então estes casos rodam com um
 * PDO SQLite vazio: se algum deles chegasse ao banco, o teste falharia com erro
 * de tabela inexistente em vez de InvalidArgumentException.
 */
class AssinaturaTest extends TestCase
{
    private function assinatura()
    {
        return new Assinatura(Auxiliar::configuracao());
    }

    public function testRecusaEndpointQueNaoSejaHttps()
    {
        $invalidos = array(
            'javascript:alert(1)',
            'http://fcm.googleapis.com/fcm/send/abcdefghijklmno',
            'curto',
            'https://' . str_repeat('a', 1200)
        );
        foreach ($invalidos as $endpoint) {
            try {
                $this->assinatura()->salvar('00000000000', array(
                    'endpoint' => $endpoint,
                    'keys' => array('p256dh' => str_repeat('a', 87), 'auth' => str_repeat('b', 22))
                ));
                $this->fail('Aceitou endpoint inválido: ' . $endpoint);
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRecusaChavesForaDoFormato()
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/abcdefghijklmnopqrst';
        $casos = array(
            array('p256dh' => 'curta', 'auth' => str_repeat('b', 22)),
            array('p256dh' => str_repeat('a', 87), 'auth' => 'curta'),
            // No meio, e não na ponta: espaço à direita seria removido pelo trim
            // e a chave passaria — o que é o comportamento certo.
            array('p256dh' => str_repeat('a', 40) . ' ' . str_repeat('a', 46), 'auth' => str_repeat('b', 22)),
            array('p256dh' => str_repeat('a', 87), 'auth' => str_repeat('b', 21) . "'")
        );
        foreach ($casos as $chaves) {
            try {
                $this->assinatura()->salvar('00000000000', array('endpoint' => $endpoint, 'keys' => $chaves));
                $this->fail('Aceitou chaves inválidas: ' . json_encode($chaves));
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRecusaCodificacaoDesconhecida()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->assinatura()->salvar('00000000000', array(
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abcdefghijklmnopqrst',
            'keys' => array('p256dh' => str_repeat('a', 87), 'auth' => str_repeat('b', 22)),
            'contentEncoding' => 'rot13'
        ));
    }

    /**
     * Identificador de aparelho ausente ou corrompido não pode impedir o alerta:
     * a linha é gravada sem dispositivo e o teto por pessoa continua valendo.
     * Aqui isso se verifica pelo tipo do erro — segue para o banco (que no teste
     * é SQLite e não entende o SQL do MySQL) em vez de parar na validação.
     */
    public function testDispositivoInvalidoNaoImpedeAGravacao()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Não foi possível ativar as notificações.');
        $this->assinatura()->salvar('00000000000', array(
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abcdefghijklmnopqrst',
            'keys' => array('p256dh' => str_repeat('a', 87), 'auth' => str_repeat('b', 22)),
            'dispositivo' => 'nao vale; DROP TABLE'
        ));
    }
}
