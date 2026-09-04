<?php

namespace pushpwa;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Transporte real, sobre minishlink/web-push.
 */
class TransporteWebPush implements Transporte
{
    /** @var Configuracao */
    private $config;
    private $ttl;

    public function __construct(Configuracao $config, $ttl = 7200)
    {
        $this->config = $config;
        $this->ttl = max(60, (int)$ttl);
    }

    public function entregar(array $assinaturas, $payload)
    {
        $this->config->exigirVapid();

        $webPush = new WebPush(array(
            'VAPID' => array(
                'subject' => $this->config->vapidAssunto(),
                'publicKey' => $this->config->vapidPublica(),
                'privateKey' => $this->config->vapidPrivada()
            )
        ), array('TTL' => $this->ttl, 'urgency' => 'high', 'batchSize' => 20));
        $webPush->setReuseVAPIDHeaders(true);
        // Esconde o tamanho real da mensagem: sem preenchimento, o tamanho do
        // pacote cifrado vaza uma pista do conteúdo para quem observa a rede.
        $webPush->setAutomaticPadding(512);

        foreach ($assinaturas as $assinatura) {
            $webPush->queueNotification(Subscription::create(array(
                'endpoint' => $assinatura['endpoint'],
                'publicKey' => $assinatura['chave_publica'],
                'authToken' => $assinatura['chave_auth'],
                'contentEncoding' => $assinatura['codificacao']
            )), $payload);
        }

        $resultados = array();
        foreach ($webPush->flush() as $relatorio) {
            $resultados[] = array(
                'endpoint' => $relatorio->getEndpoint(),
                'sucesso' => $relatorio->isSuccess(),
                'expirada' => $relatorio->isSubscriptionExpired(),
                'erro' => $relatorio->isSuccess() ? '' : (string)$relatorio->getReason()
            );
        }
        return $resultados;
    }
}
