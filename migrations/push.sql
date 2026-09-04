-- Esquema base das notificações push, aplicado no banco de cada aplicação.
--
-- As linhas NÃO são compartilhadas entre aplicações: cada uma cria estas tabelas
-- no seu próprio banco, o que preserva as chaves estrangeiras para as tabelas
-- locais e o ON DELETE CASCADE que vem de graça com elas. O que é compartilhado
-- é o código do pacote.
--
-- Depois de aplicar este arquivo, acrescente o que é da aplicação:
--   1. a chave estrangeira de `cpf` para a sua tabela de pessoas;
--   2. a coluna que aponta para o registro de origem, com a sua própria chave
--      estrangeira, passada em Fila::enfileirar() pelo campo `extras`.
-- Veja exemplos no README.

CREATE TABLE IF NOT EXISTS push_assinatura (
  codigo bigint(20) NOT NULL AUTO_INCREMENT,
  cpf varchar(11) NOT NULL,
  -- Identificador estável do aparelho, gerado no navegador. É ele, e não o
  -- endpoint, que permite aposentar a assinatura anterior quando o serviço de
  -- push recicla o endereço.
  dispositivo varchar(64) NOT NULL DEFAULT '',
  endpoint varchar(1000) NOT NULL,
  endpoint_hash char(64) NOT NULL,
  chave_publica varchar(255) NOT NULL,
  chave_auth varchar(255) NOT NULL,
  codificacao varchar(20) NOT NULL DEFAULT 'aes128gcm',
  status char(1) NOT NULL DEFAULT 'A',
  criado_em datetime NOT NULL DEFAULT current_timestamp(),
  atualizado_em datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  -- Última vez que o aparelho reconfirmou a assinatura. Sustenta a higienização.
  visto_em datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (codigo),
  UNIQUE KEY push_assinatura_endpoint (endpoint_hash),
  KEY push_assinatura_pessoa (cpf, status),
  KEY push_assinatura_dispositivo (cpf, dispositivo, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS push_notificacao (
  codigo bigint(20) NOT NULL AUTO_INCREMENT,
  -- Idempotência do gatilho: repetir a mesma ação não gera um segundo aviso.
  chave_unica varchar(100) NOT NULL,
  cpf varchar(11) NOT NULL,
  -- Ponteiro solto para o registro de origem, usado por cancelarPorReferencia().
  -- Não substitui a coluna com chave estrangeira que a aplicação acrescenta.
  referencia varchar(100) NOT NULL DEFAULT '',
  titulo varchar(150) NOT NULL,
  mensagem varchar(255) NOT NULL,
  destino varchar(255) NOT NULL,
  tag varchar(100) NOT NULL DEFAULT '',
  enviar_em datetime NOT NULL,
  status char(1) NOT NULL DEFAULT 'P',
  tentativas tinyint(3) unsigned NOT NULL DEFAULT 0,
  proxima_tentativa datetime NOT NULL DEFAULT current_timestamp(),
  criado_em datetime NOT NULL DEFAULT current_timestamp(),
  atualizado_em datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  enviado_em datetime DEFAULT NULL,
  ultimo_erro varchar(500) DEFAULT NULL,
  PRIMARY KEY (codigo),
  UNIQUE KEY push_notificacao_chave (chave_unica),
  KEY push_notificacao_processamento (status, enviar_em, proxima_tentativa),
  KEY push_notificacao_pessoa (cpf),
  KEY push_notificacao_referencia (referencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
