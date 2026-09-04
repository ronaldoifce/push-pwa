<?php

namespace campus\push;

/**
 * Execução periódica da fila, com trava.
 *
 * A trava não bloqueante é o que impede o cron de sobrepor execuções quando um
 * lote demora mais que o intervalo: sem ela, dois processos disputam a mesma
 * fila e a reserva do lote passa a depender de sorte.
 */
class Cron
{
    /**
     * @param Processador $processador já configurado com o validador do sistema.
     * @param int         $limite      itens por execução.
     * @param string|null $arquivoTrava padrão: um arquivo por sistema no temp.
     * @return array resultado do processamento, ou o motivo de não ter rodado.
     */
    public static function executar(Processador $processador, $limite = 20, $arquivoTrava = null)
    {
        $config = $processador->configuracao();
        if ($arquivoTrava === null) {
            $arquivoTrava = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                . 'campus_push_' . $config->sistema() . '.lock';
        }

        $trava = @fopen($arquivoTrava, 'c');
        if (!$trava) {
            throw new \RuntimeException('Não foi possível criar a trava do processamento de push.');
        }
        if (!flock($trava, LOCK_EX | LOCK_NB)) {
            fclose($trava);
            return array('status' => 'ignorado', 'motivo' => 'processamento_em_andamento');
        }

        try {
            $resultado = $processador->processar($limite);
            $resultado['status'] = 'ok';
            return $resultado;
        } finally {
            flock($trava, LOCK_UN);
            fclose($trava);
        }
    }
}
