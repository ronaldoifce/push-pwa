# push-campus

Notificações Web Push para aplicações PHP: assinaturas por aparelho, fila com
repetição e envio.

Feito para ser instalado em várias aplicações que já existem, sem obrigar
nenhuma delas a mudar de framework ou a dividir banco com as outras.

## O que o pacote compartilha, e o que ele não compartilha

Compartilhado é **o código**: a deduplicação por aparelho, o teto de assinaturas
por pessoa, a higienização, a fila com repetição, o envio, a limpeza de
endpoints recusados, o JavaScript do cliente e a parte de push do service
worker.

Não compartilhado são **as linhas**. Cada aplicação cria as tabelas no seu
próprio banco. Isso preserva as chaves estrangeiras para as tabelas locais e o
`ON DELETE CASCADE` que vem junto com elas — garantia que uma tabela central em
banco separado não conseguiria manter.

O pacote **não depende de framework**. Só PHP e PDO; cada aplicação escreve suas
rotas com o que já usa.

## Por que a deduplicação é por aparelho

O endpoint do serviço de push é reciclado pelo navegador por conta própria:
limpeza de dados do site, reinstalação do app, redefinição da permissão, novo
registro do serviço. Quando isso acontece, `getSubscription()` volta vazio e o
cliente cria uma assinatura nova.

Se a única chave for o endpoint, a linha anterior fica ativa para sempre e a
pessoa passa a receber a mesma notificação uma vez por linha acumulada. Por isso
o cliente gera um identificador estável do aparelho, guardado no navegador: é
ele que permite aposentar a assinatura anterior. O teto por pessoa e a
higienização das assinaturas paradas são as redes de segurança.

## Instalação

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/ronaldoifce/push-campus.git" }
    ]
}
```

```bash
composer require ifce-tiangua/push
```

Requer PHP >= 7.3 e traz `minishlink/web-push`.

## Banco

Aplique `migrations/push.sql` no banco da aplicação e depois acrescente o que é
seu: a chave estrangeira para a sua tabela de pessoas e a coluna que aponta para
o registro de origem da notificação.

```sql
ALTER TABLE push_assinatura
  ADD CONSTRAINT push_assinatura_pessoa_fk FOREIGN KEY (cpf)
      REFERENCES usuario (cpf) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE push_notificacao
  ADD COLUMN registro_id int(11) NOT NULL AFTER cpf,
  ADD CONSTRAINT push_notificacao_registro_fk FOREIGN KEY (registro_id)
      REFERENCES registro (id) ON DELETE CASCADE ON UPDATE CASCADE;
```

A coluna extra é opcional, mas é ela que faz o banco apagar a notificação
pendente junto com o registro que a originou, sem código nenhum.

## Configuração

A aplicação decide de onde vêm os valores — o pacote não lê variável de
ambiente nem constante global.

```php
use campus\push\Configuracao;

$push = new Configuracao(array(
    'pdo' => $conexao,
    'sistema' => 'minha_app',           // usado na tag padrão e na trava do cron
    'vapid_publica' => $chavePublica,
    'vapid_privada' => $chavePrivada,
    'vapid_assunto' => 'https://exemplo.org'   // URL ou mailto: de quem envia
));
```

Opcionais: `tabela_assinatura`, `tabela_notificacao`, `limite_ativas` (padrão 5)
e `dias_higienizacao` (padrão 90).

### Chaves VAPID

Um par por aplicação. Gere uma vez e guarde fora do controle de versão:

```bash
php -r "require 'vendor/autoload.php'; print_r(Minishlink\WebPush\VAPID::createVapidKeys());"
```

Trocar a chave invalida todas as assinaturas daquela aplicação — quem já tinha
alertas precisa reassinar.

## Enfileirar um aviso

```php
use campus\push\Fila;

(new Fila($push))->enfileirar(array(
    'chave_unica' => 'registro:' . $registroId,   // torna o gatilho idempotente
    'cpf' => $cpf,
    'titulo' => 'Título curto',
    'mensagem' => 'Uma linha que pode aparecer na tela de bloqueio.',
    'destino' => '/app/minha-pagina',
    'tag' => 'app-registro-' . $registroId,
    'enviar_em' => 'now',                          // ou uma data futura
    'referencia' => 'registro:' . $registroId,     // para cancelarPorReferencia()
    'extras' => array('registro_id' => $registroId)
));
```

A mensagem vai para um serviço de terceiros e fica no aparelho de quem recebe:
só coloque nela o que pode aparecer na tela de bloqueio. Nada de identificador
de pessoa, descrição, justificativa ou campo interno.

Quando o registro de origem deixa de valer por outro caminho que não a chave
estrangeira:

```php
(new Fila($push))->cancelarPorReferencia('registro:' . $registroId);
```

## As três rotas

A aplicação escreve as rotas; o pacote faz o trabalho. O exemplo usa Slim 3, mas
nada aqui depende dele:

```php
use campus\push\{Assinatura, Recursos};

// POST /push/assinar — o navegador registra o aparelho (exige sessão)
$app->post('/push/assinar', function ($request, $response) use ($push) {
    if ($request->getHeaderLine('X-Campus-PWA') !== '1') {
        return $response->withJson(array('erro' => 'Requisição inválida.'), 400);
    }
    try {
        $dados = $request->getParsedBody() ?: json_decode((string)$request->getBody(), true);
        $assinaturas = new Assinatura($push);
        $cpf = /* identificador da pessoa logada */;
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

// GET /push/{arquivo} — publica o JS do pacote, que fica fora da raiz web
$app->get('/push/{arquivo}', function ($request, $response, $args) {
    $recurso = Recursos::entregar($args['arquivo']);
    return $response->withHeader('Content-Type', $recurso['tipo'])
                    ->withHeader('ETag', $recurso['etag'])
                    ->write($recurso['conteudo']);
});
```

Em frameworks mais antigos muda só a casca — ler o corpo da requisição, montar a
resposta — e a lógica acima permanece igual.

`GET /push/chave` é pública de propósito: a chave VAPID pública já vai para todo
navegador, e o service worker precisa dela quando reassina sem nenhuma página
aberta. A chave privada nunca sai do servidor.

## Cliente

A interface — banner, diálogo, botão — é de cada aplicação. O pacote cuida do
mecanismo.

```html
<script src="/app/push/push-campus.js"></script>
<script>
    campusPush.iniciar({
        base: 'https://exemplo.org/app/',
        chaveVapid: '...',
        jaAtivo: true,
        registro: function () { return navigator.serviceWorker.ready; }
    });
    campusPush.verificarSeNecessario();
</script>
```

`jaAtivo` vem de `Assinatura::existeAtiva($cpf)` e evita repetir o pedido de
permissão. Vale a pena quando há mais de um app instalado na mesma origem: o
navegador às vezes relê `Notification.permission` como não concedida ao alternar
entre ícones instalados separadamente, e a assinatura salva continua valendo.

Chame `campusPush.solicitarPermissao(true)` no clique da pessoa e
`campusPush.verificarSeNecessario()` junto com a sincronização da interface.

## Service worker

O cache e o modo offline continuam sendo de cada aplicação. Carregue a parte de
push no topo do service worker existente:

```js
importScripts('/app/push/campus-sw.js');
campusPushSW.configurar({
    icone: 'icons/icon-192.png',
    selo: 'icons/badge-96.png',
    tagPadrao: 'app'
});
```

O `selo` precisa ser uma silhueta com fundo transparente: o Android deixa o
ícone pequeno monocromático e um PNG colorido vira um quadrado branco.

## Cron

```php
use campus\push\{Cron, Processador};

$processador = (new Processador($push))->validarCom(function (array $item) {
    // Da aplicação: o aviso ainda faz sentido? Devolver false o cancela de vez.
    return registroAindaVale((int)$item['registro_id']);
});

echo json_encode(Cron::executar($processador, 20)) . PHP_EOL;
```

A validade do registro de origem é a única regra de domínio do processamento, e
fica fora do pacote de propósito: é o que permite a mesma fila servir esquemas
diferentes.

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

[PolyForm Noncommercial 1.0.0](LICENSE) — copyright do Instituto Federal de
Educação, Ciência e Tecnologia do Ceará (IFCE). O arquivo traz o texto oficial
em inglês e uma tradução de cortesia para o português.

**Permitido:** pesquisa científica, ensino e aprendizado, uso pessoal, e uso por
instituições públicas, de ensino, de pesquisa e organizações sem fins
lucrativos — inclusive modificar e redistribuir.

**Não permitido:** usar este código para construir produto ou serviço com
finalidade comercial.

É uma licença de uso não comercial, e portanto não é uma licença de código
aberto pela definição da OSI. A escolha é deliberada: o código nasceu em serviço
público e deve continuar servindo a esse fim.
