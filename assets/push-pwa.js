/*
 * Cliente de notificações Web Push.
 *
 * Cuida só do mecanismo: permissão, assinatura, identidade do aparelho e envio
 * ao servidor. A interface — banner, diálogo, botão — fica com cada sistema,
 * porque é a parte que muda entre eles.
 *
 * Uso:
 *   pushPwa.iniciar({
 *       base: 'https://exemplo.org/app/',
 *       chaveVapid: '...',
 *       jaAtivo: true,
 *       registro: function () { return meuRegistroDoServiceWorker(); }
 *   });
 *   pushPwa.solicitarPermissao().then(function (ok) { ... });
 */
(function (global) {
    'use strict';

    // Uma verificação por aparelho dentro desta janela, em vez de uma a cada
    // navegação. O POST renova o `visto_em` no servidor, que é o que mantém o
    // aparelho fora da higienização — por isso a janela não pode ser longa
    // demais nem a verificação pode ser pulada de vez.
    var INTERVALO_VERIFICACAO_MS = 12 * 60 * 60 * 1000;
    var CHAVE_DISPOSITIVO = 'push-pwa-dispositivo';
    var CACHE_DISPOSITIVO = 'push-pwa';
    var ARQUIVO_DISPOSITIVO = 'dispositivo';

    var config = null;
    // syncronizações da interface disparam esta rotina várias vezes por
    // carregamento. Sem a trava, duas chamadas disparavam subscribe() em
    // paralelo — cada uma criando uma assinatura para o mesmo aparelho.
    var assinaturaEmAndamento = null;

    function iniciar(opcoes) {
        opcoes = opcoes || {};
        config = {
            base: new URL(opcoes.base || './', global.location.href),
            chaveVapid: String(opcoes.chaveVapid || ''),
            rotaAssinar: opcoes.rotaAssinar || 'push/assinar',
            jaAtivo: !!opcoes.jaAtivo,
            chaveVerificacao: opcoes.chaveVerificacao || 'push-pwa-verificado-em',
            registro: typeof opcoes.registro === 'function' ? opcoes.registro : registroPadrao
        };
        return config;
    }

    function registroPadrao() {
        if (!('serviceWorker' in global.navigator)) {
            return Promise.reject(new Error('Service worker indisponível.'));
        }
        return global.navigator.serviceWorker.ready;
    }

    function suportado() {
        return !!config
            && 'Notification' in global
            && 'serviceWorker' in global.navigator
            && 'PushManager' in global
            && config.chaveVapid !== '';
    }

    function permissao() {
        return suportado() ? Notification.permission : 'unsupported';
    }

    function urlDaBase(caminho) {
        return new URL(caminho, config.base).href;
    }

    function base64ParaUint8Array(base64String) {
        var padding = '='.repeat((4 - base64String.length % 4) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var brutos = global.atob(base64);
        return Uint8Array.from(Array.prototype.map.call(brutos, function (caractere) {
            return caractere.charCodeAt(0);
        }));
    }

    function verificadaRecentemente() {
        try {
            var ultima = parseInt(global.localStorage.getItem(config.chaveVerificacao) || '0', 10);
            return (Date.now() - ultima) < INTERVALO_VERIFICACAO_MS;
        } catch (erro) {
            return false;
        }
    }

    function marcarVerificada() {
        try { global.localStorage.setItem(config.chaveVerificacao, String(Date.now())); }
        catch (erro) { /* Sem localStorage, volta a verificar na próxima abertura. */ }
    }

    /*
     * Identificador estável deste aparelho. O endpoint do serviço de push é
     * reciclado sozinho (limpeza de dados do site, reinstalação do PWA,
     * redefinição da permissão, novo registro do navegador) e por isso não serve
     * para reconhecer o aparelho: sem este id, o servidor não tem como aposentar
     * a assinatura anterior e acumula uma linha nova a cada rotação.
     */
    function identificadorDoAparelho() {
        try {
            var salvo = global.localStorage.getItem(CHAVE_DISPOSITIVO);
            if (salvo && /^[A-Za-z0-9-]{8,64}$/.test(salvo)) { return salvo; }
            var novo = gerarIdentificador();
            global.localStorage.setItem(CHAVE_DISPOSITIVO, novo);
            return novo;
        } catch (erro) {
            // Sem localStorage (navegação privativa): o servidor ainda limita o
            // total de assinaturas ativas por pessoa.
            return '';
        }
    }

    function gerarIdentificador() {
        if (global.crypto && typeof global.crypto.randomUUID === 'function') {
            return global.crypto.randomUUID();
        }
        if (global.crypto && global.crypto.getRandomValues) {
            var bytes = new Uint8Array(16);
            global.crypto.getRandomValues(bytes);
            return Array.prototype.map.call(bytes, function (byte) {
                return ('0' + byte.toString(16)).slice(-2);
            }).join('');
        }
        return String(Date.now()) + '-' + String(Math.random()).slice(2, 12);
    }

    /*
     * O service worker não enxerga o localStorage, e é ele quem reassina quando
     * o navegador descarta a assinatura em segundo plano. O espelho no cache
     * mantém essa reassinatura ligada ao mesmo aparelho.
     */
    function espelharDispositivo(dispositivo) {
        if (!dispositivo || !('caches' in global)) { return Promise.resolve(); }
        return global.caches.open(CACHE_DISPOSITIVO).then(function (cache) {
            return cache.put(urlDaBase(ARQUIVO_DISPOSITIVO), new Response(dispositivo));
        }).catch(function () { /* O teto por pessoa no servidor cobre a falha. */ });
    }

    function salvarAssinatura(assinatura) {
        var dados = assinatura.toJSON();
        dados.contentEncoding = global.PushManager.supportedContentEncodings
            ? global.PushManager.supportedContentEncodings[0] : 'aes128gcm';
        dados.dispositivo = identificadorDoAparelho();
        espelharDispositivo(dados.dispositivo);
        return fetch(urlDaBase(config.rotaAssinar), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Push-Pwa': '1'
            },
            body: JSON.stringify(dados)
        }).then(function (resposta) {
            var tipo = resposta.headers.get('Content-Type') || '';
            if (!resposta.ok || tipo.indexOf('application/json') === -1) {
                throw new Error('Não foi possível vincular este aparelho.');
            }
            return resposta.json();
        });
    }

    function executarAssinatura() {
        return config.registro().then(function (registro) {
            return registro.pushManager.getSubscription().then(function (assinatura) {
                if (assinatura) { return assinatura; }
                return registro.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: base64ParaUint8Array(config.chaveVapid)
                });
            });
        }).then(function (assinatura) {
            return salvarAssinatura(assinatura);
        }).then(function (resultado) {
            marcarVerificada();
            return resultado;
        });
    }

    function garantirAssinatura(repetir) {
        if (!suportado() || Notification.permission !== 'granted') {
            return Promise.resolve(false);
        }
        if (!assinaturaEmAndamento) {
            assinaturaEmAndamento = executarAssinatura();
        } else if (repetir) {
            // Nova tentativa pedida pelo usuário depois de uma falha: espera a
            // anterior terminar em vez de abrir um segundo subscribe() em
            // paralelo, que geraria duas assinaturas para o mesmo aparelho.
            assinaturaEmAndamento = assinaturaEmAndamento
                .catch(function () { return false; })
                .then(function () { return executarAssinatura(); });
        }
        return assinaturaEmAndamento;
    }

    /*
     * Reverifica a assinatura quando a permissão já está concedida, no máximo
     * uma vez por janela. Chame junto com a sincronização da interface.
     */
    function verificarSeNecessario() {
        if (!suportado() || Notification.permission !== 'granted' || verificadaRecentemente()) {
            return Promise.resolve(false);
        }
        return garantirAssinatura(false).catch(function () { return false; });
    }

    /**
     * @param {boolean} explicito true quando parte de um clique do usuário: aí
     *        vale insistir mesmo com assinatura já ativa e repetir após falha.
     */
    function solicitarPermissao(explicito) {
        if (!suportado()) { return Promise.resolve(false); }
        // Assinatura já ativa no servidor: não chama requestPermission() de novo.
        // Quando há mais de um app instalado na mesma origem, o pedido do
        // sistema reaparecia ao alternar entre os ícones.
        if (config.jaAtivo && !explicito) { return Promise.resolve(true); }
        if (Notification.permission === 'denied') { return Promise.resolve(false); }

        var pedido = Notification.permission === 'granted'
            ? Promise.resolve('granted') : Notification.requestPermission();
        return pedido.then(function (concedida) {
            if (concedida !== 'granted') { return false; }
            return garantirAssinatura(explicito).then(function () { return true; });
        });
    }

    global.pushPwa = {
        iniciar: iniciar,
        suportado: suportado,
        permissao: permissao,
        solicitarPermissao: solicitarPermissao,
        garantirAssinatura: garantirAssinatura,
        verificarSeNecessario: verificarSeNecessario,
        identificadorDoAparelho: identificadorDoAparelho
    };
}(window));
