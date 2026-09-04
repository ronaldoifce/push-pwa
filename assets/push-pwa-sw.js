/*
 * Parte de notificações do service worker, compartilhada pelas aplicações que
 * instalam o pacote.
 *
 * Não é um service worker inteiro: o cache e o modo offline continuam sendo de
 * cada aplicação. Carregue no topo do service worker que ela já tem:
 *
 *   importScripts('/app/push/push-pwa-sw.js');
 *   pushPwaSW.configurar({ icone: 'icons/icon-192.png', selo: 'icons/badge-96.png' });
 *
 * O `importScripts` resolve caminho relativo contra o script, não contra o
 * escopo, então use caminho absoluto ou monte a URL com self.registration.scope.
 */
(function (self) {
    'use strict';

    var opcoes = {
        rotaAssinar: 'push/assinar',
        rotaChave: 'push/chave',
        icone: '',
        selo: '',
        tagPadrao: 'app'
    };

    var CACHE_DISPOSITIVO = 'push-pwa';
    var ARQUIVO_DISPOSITIVO = 'dispositivo';

    function configurar(personalizadas) {
        personalizadas = personalizadas || {};
        Object.keys(personalizadas).forEach(function (chave) {
            opcoes[chave] = personalizadas[chave];
        });
        return opcoes;
    }

    function urlDoEscopo(caminho) {
        return new URL(caminho, self.registration.scope).href;
    }

    function dadosNotificacao(payload) {
        payload = payload || {};
        var destino = payload.url ? new URL(payload.url, self.registration.scope).href
            : self.registration.scope;
        var corpo = {
            body: payload.body || '',
            tag: payload.tag || opcoes.tagPadrao,
            renotify: false,
            data: { url: destino }
        };
        if (opcoes.icone) { corpo.icon = urlDoEscopo(opcoes.icone); }
        // O Android deixa o ícone pequeno monocromático: o selo precisa ser uma
        // silhueta com fundo transparente, senão vira um quadrado branco.
        if (opcoes.selo) { corpo.badge = urlDoEscopo(opcoes.selo); }
        return { title: payload.title || 'Aviso', options: corpo };
    }

    self.addEventListener('push', function (evento) {
        var payload = {};
        if (evento.data) {
            try { payload = evento.data.json(); }
            catch (erro) { payload = { body: evento.data.text() }; }
        }
        var notificacao = dadosNotificacao(payload);
        evento.waitUntil(self.registration.showNotification(notificacao.title, notificacao.options));
    });

    self.addEventListener('notificationclick', function (evento) {
        evento.notification.close();
        var destino = evento.notification.data && evento.notification.data.url
            ? evento.notification.data.url : self.registration.scope;
        evento.waitUntil(
            self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (janelas) {
                for (var i = 0; i < janelas.length; i++) {
                    if ('focus' in janelas[i]) {
                        if ('navigate' in janelas[i]) { janelas[i].navigate(destino); }
                        return janelas[i].focus();
                    }
                }
                return self.clients.openWindow ? self.clients.openWindow(destino) : Promise.resolve();
            })
        );
    });

    /*
     * O navegador descarta a assinatura por conta própria (troca de registro do
     * serviço de push, redefinição da permissão, atualização do app). Sem tratar
     * este evento, a página só percebe na próxima abertura e cria uma linha
     * nova, deixando a antiga ativa. Aqui o aparelho reassina na hora e informa
     * qual endpoint foi descartado.
     */
    self.addEventListener('pushsubscriptionchange', function (evento) {
        evento.waitUntil(reassinar(evento));
    });

    function chaveDoServidor(evento) {
        var anterior = evento.oldSubscription && evento.oldSubscription.options
            ? evento.oldSubscription.options.applicationServerKey : null;
        if (anterior) { return Promise.resolve(anterior); }
        // Safari e Firefox nem sempre expõem a chave da assinatura antiga.
        return fetch(urlDoEscopo(opcoes.rotaChave), { credentials: 'same-origin' })
            .then(function (resposta) {
                if (!resposta.ok) { throw new Error('Chave de notificações indisponível.'); }
                return resposta.json();
            })
            .then(function (dados) { return base64ParaUint8Array(dados.chave); });
    }

    function base64ParaUint8Array(base64String) {
        var padding = '='.repeat((4 - base64String.length % 4) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var brutos = self.atob(base64);
        return Uint8Array.from(Array.prototype.map.call(brutos, function (caractere) {
            return caractere.charCodeAt(0);
        }));
    }

    /*
     * O identificador do aparelho vive no localStorage, que o service worker não
     * enxerga. A página o espelha neste cache justamente para a reassinatura em
     * segundo plano continuar apontando para o mesmo aparelho.
     */
    function dispositivoSalvo() {
        return caches.open(CACHE_DISPOSITIVO)
            .then(function (cache) { return cache.match(urlDoEscopo(ARQUIVO_DISPOSITIVO)); })
            .then(function (resposta) { return resposta ? resposta.text() : ''; })
            .catch(function () { return ''; });
    }

    function reassinar(evento) {
        var anterior = evento.oldSubscription ? evento.oldSubscription.endpoint : null;
        return chaveDoServidor(evento).then(function (chave) {
            return self.registration.pushManager.getSubscription().then(function (atual) {
                if (atual) { return atual; }
                return self.registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: chave
                });
            });
        }).then(function (assinatura) {
            return dispositivoSalvo().then(function (dispositivo) {
                return { assinatura: assinatura, dispositivo: dispositivo };
            });
        }).then(function (contexto) {
            var dados = contexto.assinatura.toJSON();
            dados.contentEncoding = self.PushManager && self.PushManager.supportedContentEncodings
                ? self.PushManager.supportedContentEncodings[0] : 'aes128gcm';
            dados.dispositivo = contexto.dispositivo;
            dados.endpointAnterior = anterior;
            return fetch(urlDoEscopo(opcoes.rotaAssinar), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-Push-Pwa': '1' },
                body: JSON.stringify(dados)
            });
        }).catch(function () {
            /* Sem sessão válida aqui; a próxima abertura do sistema reassina. */
        });
    }

    self.pushPwaSW = { configurar: configurar, dadosNotificacao: dadosNotificacao };
}(self));
