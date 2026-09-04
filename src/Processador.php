<?php

namespace campus\push;

/**
 * Drena a fila: aposenta assinaturas paradas, reserva um lote, confere se cada
 * aviso ainda faz sentido e entrega.
 *
 * A regra de "ainda faz sentido" é da aplicação, não do pacote: pode ser um
 * agendamento continuar ativo e no futuro, ou um registro ainda existir.
 * Por isso ela chega como função em `validarCom()`, e não como SQL aqui dentro:
 * é o único ponto de domínio do processamento, e mantê-lo fora é o que permite
 * a mesma fila servir sistemas com esquemas diferentes.
 */
class Processador
{
    /** @var Configuracao */
    private $config;
    /** @var Assinatura */
    private $assinaturas;
    /** @var Fila */
    private $fila;
    /** @var Transporte */
    private $transporte;
    /** @var callable|null */
    private $validador;

    /**
     * Assinatura e Fila entram por injeção para que o processamento possa ser
     * testado sem MySQL: o SQL das duas é propositalmente MySQL, igual ao de
     * produção, e não roda em banco de mentira.
     */
    public function __construct(
        Configuracao $config,
        Transporte $transporte = null,
        Assinatura $assinaturas = null,
        Fila $fila = null
    ) {
        $this->config = $config;
        $this->assinaturas = $assinaturas ?: new Assinatura($config);
        $this->fila = $fila ?: new Fila($config);
        $this->transporte = $transporte ?: new TransporteWebPush($config);
    }

    /**
     * @param callable $validador recebe a linha da fila e devolve true para
     *                            enviar, false para cancelar. Um aviso cancelado
     *                            sai da fila para sempre — use para o registro
     *                            de origem que sumiu ou perdeu a validade.
     */
    public function validarCom(callable $validador)
    {
        $this->validador = $validador;
        return $this;
    }

    public function configuracao()
    {
        return $this->config;
    }

    public function fila()
    {
        return $this->fila;
    }

    public function assinaturas()
    {
        return $this->assinaturas;
    }

    public function processar($limite = 20)
    {
        $this->config->exigirVapid();
        // Aproveita a passagem do cron para aposentar assinaturas paradas. Sem
        // isso, um endpoint já reciclado pelo navegador segue recebendo envio
        // até falhar, e conta como dispositivo ativo da pessoa.
        $limpas = $this->assinaturas->higienizar();
        $itens = $this->fila->reservarLote($limite);
        $resultado = array(
            'reservadas' => count($itens),
            'enviadas' => 0,
            'canceladas' => 0,
            'falhas' => 0,
            'assinaturas_limpas' => $limpas
        );

        foreach ($itens as $item) {
            try {
                if ($this->validador !== null && !call_user_func($this->validador, $item)) {
                    $this->fila->marcarCancelada($item['codigo'], 'Registro de origem não está mais válido.');
                    $resultado['canceladas']++;
                    continue;
                }
                $this->entregar($item);
                $this->fila->marcarEnviada($item['codigo']);
                $resultado['enviadas']++;
            } catch (\Throwable $e) {
                $this->fila->marcarFalha($item['codigo'], $e->getMessage());
                $resultado['falhas']++;
            }
        }
        return $resultado;
    }

    private function entregar(array $item)
    {
        $assinaturas = $this->assinaturas->getAtivasByCpf($item['cpf']);
        if (!$assinaturas) {
            throw new \RuntimeException('Nenhum dispositivo ativo para receber a notificação.');
        }

        // Só o que aparece na tela do aparelho. Nada de CPF, descrição,
        // justificativa ou qualquer campo do registro de origem: a notificação
        // atravessa um serviço de terceiros e fica no aparelho de quem recebe.
        $payload = json_encode(array(
            'title' => $item['titulo'],
            'body' => $item['mensagem'],
            'url' => $item['destino'],
            'tag' => $item['tag'] !== '' ? $item['tag'] : $this->config->sistema()
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $enviadas = 0;
        foreach ($this->transporte->entregar($assinaturas, $payload) as $relatorio) {
            if (!empty($relatorio['sucesso'])) {
                $enviadas++;
            } elseif (!empty($relatorio['expirada'])) {
                $this->assinaturas->desativarPorEndpoint($relatorio['endpoint']);
            }
        }
        if ($enviadas < 1) {
            throw new \RuntimeException('O serviço de push não confirmou o envio.');
        }
    }
}
