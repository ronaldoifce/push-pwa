<?php

namespace campus\push\tests;

use campus\push\Cron;
use campus\push\Processador;
use PHPUnit\Framework\TestCase;

class CronTest extends TestCase
{
    private $trava;

    protected function setUp(): void
    {
        $this->trava = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'campus_push_teste_' . getmypid() . '.lock';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->trava)) {
            @unlink($this->trava);
        }
    }

    private function processador()
    {
        $config = Auxiliar::configuracao();
        return new Processador(
            $config,
            new TransporteFalso(),
            new AssinaturaFalsa($config),
            new FilaFalsa($config)
        );
    }

    public function testExecucaoNormalDevolveOResultadoDoProcessamento()
    {
        $resultado = Cron::executar($this->processador(), 10, $this->trava);

        $this->assertSame('ok', $resultado['status']);
        $this->assertSame(0, $resultado['reservadas']);
    }

    /**
     * Um lote que demora mais que o intervalo do cron não pode ser processado
     * por dois processos ao mesmo tempo: a reserva do lote deixaria de valer.
     */
    public function testExecucaoConcorrenteEIgnoradaEmVezDeDisputarAFila()
    {
        $ocupada = fopen($this->trava, 'c');
        flock($ocupada, LOCK_EX | LOCK_NB);

        $resultado = Cron::executar($this->processador(), 10, $this->trava);

        flock($ocupada, LOCK_UN);
        fclose($ocupada);

        $this->assertSame('ignorado', $resultado['status']);
        $this->assertSame('processamento_em_andamento', $resultado['motivo']);
    }

    public function testATravaELiberadaDepoisDaExecucao()
    {
        Cron::executar($this->processador(), 10, $this->trava);

        $arquivo = fopen($this->trava, 'c');
        $this->assertTrue(flock($arquivo, LOCK_EX | LOCK_NB), 'A trava continuou presa após a execução.');
        flock($arquivo, LOCK_UN);
        fclose($arquivo);
    }
}
