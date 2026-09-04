<?php

namespace pushpwa\tests;

use pushpwa\Assinatura;
use pushpwa\Configuracao;
use pushpwa\Fila;
use pushpwa\Transporte;

/**
 * Dublês para testar o processamento sem MySQL. O SQL de Assinatura e Fila é
 * MySQL de propósito — igual ao de produção — e por isso não roda em SQLite;
 * o que se testa aqui é a coordenação entre eles.
 */
abstract class Auxiliar
{
    public static function configuracao(array $extras = array())
    {
        return new Configuracao(array_merge(array(
            'pdo' => new \PDO('sqlite::memory:'),
            'sistema' => 'teste',
            'vapid_publica' => 'chave-publica-de-teste',
            'vapid_privada' => 'chave-privada-de-teste',
            'vapid_assunto' => 'https://exemplo.invalido'
        ), $extras));
    }
}

class TransporteFalso implements Transporte
{
    public $payloads = array();
    public $respostas;

    public function __construct(array $respostas = null)
    {
        $this->respostas = $respostas;
    }

    public function entregar(array $assinaturas, $payload)
    {
        $this->payloads[] = $payload;
        if ($this->respostas !== null) {
            return $this->respostas;
        }
        $resultado = array();
        foreach ($assinaturas as $assinatura) {
            $resultado[] = array(
                'endpoint' => $assinatura['endpoint'],
                'sucesso' => true,
                'expirada' => false,
                'erro' => ''
            );
        }
        return $resultado;
    }
}

class AssinaturaFalsa extends Assinatura
{
    public $ativas = array();
    public $higienizadas = 0;
    public $desativadas = array();

    public function higienizar()
    {
        return $this->higienizadas;
    }

    public function getAtivasByCpf($cpf)
    {
        return $this->ativas;
    }

    public function desativarPorEndpoint($endpoint)
    {
        $this->desativadas[] = $endpoint;
        return true;
    }
}

class FilaFalsa extends Fila
{
    public $lote = array();
    public $enviadas = array();
    public $canceladas = array();
    public $falhas = array();

    public function reservarLote($limite)
    {
        return $this->lote;
    }

    public function marcarEnviada($codigo)
    {
        $this->enviadas[] = $codigo;
    }

    public function marcarCancelada($codigo, $motivo = '')
    {
        $this->canceladas[] = $codigo;
    }

    public function marcarFalha($codigo, $erro)
    {
        $this->falhas[$codigo] = $erro;
    }
}
