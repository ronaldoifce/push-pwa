<?php

namespace pushpwa\tests;

use pushpwa\Processador;
use PHPUnit\Framework\TestCase;

class ProcessadorTest extends TestCase
{
    private $config;
    private $assinaturas;
    private $fila;
    private $transporte;

    protected function setUp(): void
    {
        $this->config = Auxiliar::configuracao();
        $this->assinaturas = new AssinaturaFalsa($this->config);
        $this->assinaturas->ativas = array(array(
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/aparelho-1',
            'chave_publica' => str_repeat('a', 87),
            'chave_auth' => str_repeat('b', 22),
            'codificacao' => 'aes128gcm'
        ));
        $this->fila = new FilaFalsa($this->config);
        $this->transporte = new TransporteFalso();
    }

    private function processador()
    {
        return new Processador($this->config, $this->transporte, $this->assinaturas, $this->fila);
    }

    private function item(array $extras = array())
    {
        return array_merge(array(
            'codigo' => 7,
            'cpf' => '00000000000',
            'titulo' => 'Aviso curto',
            'mensagem' => 'Uma linha para a tela de bloqueio.',
            'destino' => '/app/minha-pagina',
            'tag' => 'app-registro-7'
        ), $extras);
    }

    public function testEnviaEMarcaComoEnviada()
    {
        $this->fila->lote = array($this->item());

        $resultado = $this->processador()->processar(10);

        $this->assertSame(1, $resultado['reservadas']);
        $this->assertSame(1, $resultado['enviadas']);
        $this->assertSame(0, $resultado['falhas']);
        $this->assertSame(array(7), $this->fila->enviadas);
    }

    /**
     * A validade do registro de origem é do sistema, não do pacote. Quando ela
     * some, o aviso sai da fila para sempre em vez de ficar tentando.
     */
    public function testValidadorNegativoCancelaSemEnviar()
    {
        $this->fila->lote = array($this->item());

        $resultado = $this->processador()
            ->validarCom(function (array $item) { return false; })
            ->processar(10);

        $this->assertSame(1, $resultado['canceladas']);
        $this->assertSame(0, $resultado['enviadas']);
        $this->assertSame(array(7), $this->fila->canceladas);
        $this->assertSame(array(), $this->transporte->payloads);
    }

    public function testSemAparelhoAtivoARegistraComoFalhaEnaoComoEnvio()
    {
        $this->assinaturas->ativas = array();
        $this->fila->lote = array($this->item());

        $resultado = $this->processador()->processar(10);

        $this->assertSame(1, $resultado['falhas']);
        $this->assertSame(0, $resultado['enviadas']);
        $this->assertArrayHasKey(7, $this->fila->falhas);
    }

    /**
     * 404/410 do provedor significa endpoint reciclado: a assinatura sai de
     * cena na hora, senão ela conta como aparelho ativo da pessoa para sempre.
     */
    public function testAssinaturaExpiradaEDesativada()
    {
        $this->fila->lote = array($this->item());
        $this->transporte->respostas = array(array(
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/aparelho-1',
            'sucesso' => false,
            'expirada' => true,
            'erro' => '410 Gone'
        ));

        $resultado = $this->processador()->processar(10);

        $this->assertSame(1, $resultado['falhas']);
        $this->assertSame(
            array('https://fcm.googleapis.com/fcm/send/aparelho-1'),
            $this->assinaturas->desativadas
        );
    }

    /**
     * A notificação atravessa um serviço de terceiros e fica no aparelho de quem
     * recebe: só pode levar o que aparece na tela.
     */
    public function testPayloadNaoLevaCpfNemCamposDoRegistroDeOrigem()
    {
        $this->fila->lote = array($this->item(array(
            'referencia' => 'registro:7',
            'justificativa' => 'texto interno que não pode sair',
            'ultimo_erro' => null
        )));

        $this->processador()->processar(10);

        $this->assertCount(1, $this->transporte->payloads);
        $payload = json_decode($this->transporte->payloads[0], true);

        $this->assertSame(array('title', 'body', 'url', 'tag'), array_keys($payload));
        $this->assertSame('Aviso curto', $payload['title']);
        $this->assertStringNotContainsString('00000000000', $this->transporte->payloads[0]);
        $this->assertStringNotContainsString('justificativa', $this->transporte->payloads[0]);
        $this->assertStringNotContainsString('registro:7', $this->transporte->payloads[0]);
    }

    public function testHigienizacaoEntraNoResultadoDoProcessamento()
    {
        $this->assinaturas->higienizadas = 3;
        $this->fila->lote = array();

        $resultado = $this->processador()->processar(10);

        $this->assertSame(3, $resultado['assinaturas_limpas']);
        $this->assertSame(0, $resultado['reservadas']);
    }
}
