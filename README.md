# ifce-tiangua/push

Notificações Web Push compartilhadas pelos sistemas do IFCE Campus Tianguá
(Agende, RA, eventos).

## O que é compartilhado, e o que não é

Compartilhado é **o código**: a deduplicação por aparelho, o teto por pessoa, a
higienização, a fila com repetição, o envio, a limpeza de endpoints recusados, o
JavaScript do cliente e a parte de push do service worker.

Não compartilhado são **as linhas**. Cada sistema cria as tabelas no seu próprio
banco. Isso preserva as chaves estrangeiras para as tabelas locais (`pessoa`,
`agendamento`, `acesso`) e o `ON DELETE CASCADE` que vem de graça com elas —
garantia que uma tabela central em banco separado não conseguiria manter.

O pacote **não depende de framework**. Os sistemas do campus usam Slim 2 e
Slim 3; aqui só há PHP e PDO, e cada sistema escreve suas rotas na versão que já
usa.

## Instalação

No `composer.json` do sistema:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/ronaldoifce/push-campus.git" }
    ],
    "require": {
        "ifce-tiangua/push": "^1.0"
    }
}
```

```bash
composer require ifce-tiangua/push
```

Requer PHP >= 7.3 e traz `minishlink/web-push` (que puxa guzzle 7, já presente
nos três sistemas via `google/apiclient`).

## Banco

Aplique `migrations/push.sql` no banco do sistema e depois acrescente o que é
seu: a chave estrangeira do CPF e a coluna que aponta para o registro de origem.

```sql
-- Exemplo no RA, onde a origem é a retirada registrada em `acesso`:
ALTER TABLE push_assinatura
  ADD CONSTRAINT push_assinatura_pessoa_fk FOREIGN KEY (cpf)
      REFERENCES estudante (cpf) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE push_notificacao
  ADD COLUMN acesso_id int(11) NOT NULL AFTER cpf,
  ADD CONSTRAINT push_notificacao_acesso_fk FOREIGN KEY (acesso_id)
      REFERENCES acesso (id) ON DELETE CASCADE ON UPDATE CASCADE;
```

## Configuração

Cada sistema decide de onde vêm os valores — o Agende usa `.env`, o RA usa
`src/settings.php`. O pacote não lê ambiente nem constante global.

```php
use campus\push\Configuracao;

$push = new Configuracao(array(
    'pdo' => DBAgende::getAgende(),
    'sistema' => 'agende',
    'vapid_publica' => getenv('PUSH_VAPID_PUBLIC_KEY'),
    'vapid_privada' => getenv('PUSH_VAPID_PRIVATE_KEY'),
    'vapid_assunto' => 'https://sistemas.tiangua.ifce.edu.br'
));
```

Opcionais: `tabela_assinatura`, `tabela_notificacao`, `limite_ativas` (padrão 5)
e `dias_higienizacao` (padrão 90).

### Chaves VAPID

Um par por sistema. Gere uma vez e guarde fora do git:

```bash
php -r "require 'vendor/autoload.php'; print_r(Minishlink\WebPush\VAPID::createVapidKeys());"
```

Trocar a chave de um sistema invalida todas as assinaturas dele — quem já tinha
alertas precisa reassinar.

## Enfileirar um aviso

```php
use campus\push\Fila;

(new Fila($push))->enfileirar(array(
    'chave_unica' => 'retirada:' . $acessoId,   // torna o gatilho idempotente
    'cpf' => $cpf,
    'titulo' => 'Lanche retirado',
    'mensagem' => 'Sua retirada foi registrada às ' . date('H:i') . '.',
    'destino' => '/ra/carteira/estudante',
    'tag' => 'ra-retirada-' . $acessoId,
    'enviar_em' => 'now',                        // ou uma data futura
    'referencia' => 'acesso:' . $acessoId,       // para cancelarPorReferencia()
    'extras' => array('acesso_id' => $acessoId)  // a coluna com a FK do sistema
));
```

A mensagem vai para um serviço de terceiros e fica no aparelho de quem recebe:
só coloque nela o que pode aparecer na tela de bloqueio. Nada de CPF, descrição,
justificativa ou campo interno.

Quando o registro de origem deixa de existir por outro caminho que não a FK:

```php
(new Fila($push))->cancelarPorReferencia('acesso:' . $acessoId);
```

## As três rotas

O sistema escreve as rotas; o pacote faz o trabalho. Em Slim 3:

```php
// POST /services/push/assinar — o navegador registra o aparelho
$app->post('/push/assinar', function ($request, $response) use ($push) {
    if ($request->getHeaderLine('X-Campus-PWA') !== '1') {
        return $response->withJson(array('erro' => 'Requisição inválida.'), 400);
    }
    try {
        $dados = $request->getParsedBody() ?: json_decode((string)$request->getBody(), true);
        $assinaturas = new campus\push\Assinatura($push);
        $cpf = Autenticador::instanciar()->getUsuario();
        $assinaturas->salvar($cpf, $dados);
        // O service worker informa o endpoint que o navegador descartou.
        if (!empty($dados['endpointAnterior'])) {
            $assinaturas->desativarDoUsuario($cpf, $dados['endpointAnterior']);
        }
        return $response->withJson(array('ativa' => true), 201);
    } catch (\InvalidArgumentException $e) {
        return $response->withJson(array('erro' => $e->getMessage()), 422);
    } catch (\Throwable $e) {
        error_log('Falha ao salvar assinatura de push.');
        return $response->withJson(array('erro' => 'Não foi possível ativar as notificações.'), 500);
    }
});

// GET /push/chave — pública: o service worker reassina sem página aberta
$app->get('/push/chave', function ($request, $response) use ($push) {
    return $response->withJson(array('chave' => $push->vapidPublica()), 200);
});

// GET /push/{arquivo} — publica o JS do pacote, que não fica em vendor/ acessível
$app->get('/push/{arquivo}', function ($request, $response, $args) {
    $recurso = campus\push\Recursos::entregar($args['arquivo']);
    return $response->withHeader('Content-Type', $recurso['tipo'])
                    ->withHeader('ETag', $recurso['etag'])
                    ->write($recurso['conteudo']);
});
```

Em Slim 2 muda só a casca:

```php
$app->post('/push/assinar', function () use ($app, $push) {
    $dados = json_decode($app->request->getBody(), true);
    // ... mesma lógica ...
    $app->response->headers->set('Content-Type', 'application/json');
    $app->response->setStatus(201);
    $app->response->setBody(json_encode(array('ativa' => true)));
});
```

## Cliente

A interface (banner, diálogo, botão) é de cada sistema. O pacote cuida do
mecanismo.

```html
<script src="/ra/push/push-campus.js"></script>
<script>
    campusPush.iniciar({
        base: 'https://sistemas.tiangua.ifce.edu.br/ra/',
        chaveVapid: '{{ push_vapid_public_key }}',
        jaAtivo: {{ push_ja_ativo ? 'true' : 'false' }},
        registro: function () { return navigator.serviceWorker.ready; }
    });
    campusPush.verificarSeNecessario();
</script>
```

`jaAtivo` vem de `Assinatura::existeAtiva($cpf)` e evita repetir o pedido de
permissão. Todos os sistemas do campus estão na mesma origem, e o navegador às
vezes relê `Notification.permission` como não concedida ao alternar entre ícones
instalados separadamente.

Chame `campusPush.solicitarPermissao(true)` no clique do usuário e
`campusPush.verificarSeNecessario()` junto com a sincronização da interface.

## Service worker

O cache e o modo offline continuam sendo de cada sistema. Carregue a parte de
push no topo do service worker existente:

```js
importScripts('/ra/push/campus-sw.js');
campusPushSW.configurar({
    icone: 'icons/icon-192.png',
    selo: 'icons/badge-96.png',
    tagPadrao: 'ra'
});
```

O `selo` precisa ser uma silhueta com fundo transparente: o Android deixa o
ícone pequeno monocromático e um PNG colorido vira um quadrado branco.

## Cron

```php
use campus\push\{Cron, Processador};

$processador = (new Processador($push))->validarCom(function (array $item) {
    // Do sistema: o aviso ainda faz sentido? Devolver false o cancela de vez.
    return Acesso::existe((int)$item['acesso_id']);
});

echo json_encode(Cron::executar($processador, 20)) . PHP_EOL;
```

A trava não bloqueante impede sobreposição quando um lote demora mais que o
intervalo do cron. O resultado traz `reservadas`, `enviadas`, `canceladas`,
`falhas` e `assinaturas_limpas`.

## Testes

```bash
composer install
composer test
```

O SQL de `Assinatura` e `Fila` é MySQL, igual ao de produção, e por isso não roda
em banco de mentira: os testes cobrem a validação (que acontece antes de tocar o
banco) e a coordenação do processamento, com dublês de fila, assinatura e
transporte.

## Contato

Manutenção: Ronaldo Ribeiro — <ronaldo.ribeiro@ifce.edu.br>
Dúvidas e problemas: <https://github.com/ronaldoifce/push-campus/issues>

## Licença

MIT — veja [LICENSE](LICENSE).
