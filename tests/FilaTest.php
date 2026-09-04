<?php

namespace campus\push\tests;

use campus\push\Fila;
use PHPUnit\Framework\TestCase;

class FilaTest extends TestCase
{
    private function fila()
    {
        return new Fila(Auxiliar::configuracao());
    }

    private function aviso(array $extras = array())
    {
        return array_merge(array(
            'chave_unica' => 'lembrete:1',
            'cpf' => '00000000000',
            'titulo' => 'Lembrete',
            'mensagem' => 'Sua reserva começa em breve.',
            'destino' => '/agende/usuario/meus-agendamentos'
        ), $extras);
    }

    public function testExigeOsCamposQueAparecemNaTela()
    {
        foreach (array('chave_unica', 'cpf', 'titulo', 'mensagem', 'destino') as $faltando) {
            $aviso = $this->aviso();
            unset($aviso[$faltando]);
            try {
                $this->fila()->enfileirar($aviso);
                $this->fail('Aceitou notificação sem "' . $faltando . '".');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($faltando, $e->getMessage());
            }
        }
    }

    /**
     * As colunas extras viram nomes de coluna em um INSERT montado na hora, e
     * não há placeholder para identificador — a validação é a única barreira.
     */
    public function testRecusaColunaExtraQueNaoSejaIdentificador()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fila()->enfileirar($this->aviso(array(
            'extras' => array('agendamento_codigo = 1; DROP TABLE pessoa --' => 1)
        )));
    }

    public function testRecusaColunaExtraQueSobrescreveCampoDaFila()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fila()->enfileirar($this->aviso(array(
            'extras' => array('destino' => 'https://exemplo.invalido/')
        )));
    }

    public function testRecusaDataDeEnvioIlegivel()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->fila()->enfileirar($this->aviso(array('enviar_em' => 'quinta que vem talvez')));
    }

    /**
     * Um aviso completo passa da validação e vai ao banco — que no teste é
     * SQLite e não entende INSERT IGNORE. O que importa aqui é que o erro deixou
     * de ser de validação.
     */
    public function testAvisoCompletoChegaAoBanco()
    {
        $resultado = $this->fila()->enfileirar($this->aviso(array(
            'enviar_em' => '2026-09-04 12:00:00',
            'referencia' => 'agendamento:1',
            'extras' => array('agendamento_codigo' => 1)
        )));
        $this->assertFalse($resultado);
    }
}
