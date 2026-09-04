<?php

namespace pushpwa;

/**
 * Entrega de fato ao serviço de push. Existe como interface por dois motivos:
 * permite testar o processador sem rede e sem chave VAPID, e isola o pacote de
 * uma troca futura da biblioteca de Web Push.
 */
interface Transporte
{
    /**
     * @param array  $assinaturas linhas de push_assinatura (endpoint, chave_publica,
     *                            chave_auth, codificacao).
     * @param string $payload     JSON já pronto para o service worker.
     * @return array lista de array('endpoint' => string, 'sucesso' => bool,
     *               'expirada' => bool, 'erro' => string).
     */
    public function entregar(array $assinaturas, $payload);
}
