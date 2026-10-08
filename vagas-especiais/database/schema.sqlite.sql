-- Vagas Especiais Niterói — schema SQLite (somente desenvolvimento local)
-- Espelha database/schema.sql. Criado por: php database/install.php

CREATE TABLE IF NOT EXISTS usuarios (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  nome            TEXT NOT NULL,
  email           TEXT NOT NULL UNIQUE,
  senha_hash      TEXT NOT NULL,
  papel           TEXT NOT NULL DEFAULT 'usuario' CHECK (papel IN ('usuario','admin')),
  suspenso        INTEGER NOT NULL DEFAULT 0,
  aceite_termos_em TEXT NULL,
  criado_em       TEXT NOT NULL,
  atualizado_em   TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS perfis (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  usuario_id      INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
  tipo            TEXT NOT NULL CHECK (tipo IN ('PCD','IDOSO','ROTATIVO')),
  status          TEXT NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente','em_analise','aprovado','recusado')),
  motivo_recusa   TEXT NULL,
  criado_em       TEXT NOT NULL,
  atualizado_em   TEXT NOT NULL,
  UNIQUE (usuario_id, tipo)
);
CREATE INDEX IF NOT EXISTS idx_perfis_status ON perfis(status);

CREATE TABLE IF NOT EXISTS comprovacoes (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  usuario_id     INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
  perfil         TEXT NOT NULL CHECK (perfil IN ('PCD','IDOSO')),
  arquivo        TEXT NOT NULL,
  nome_original  TEXT NOT NULL,
  mime           TEXT NOT NULL,
  tamanho        INTEGER NOT NULL,
  status         TEXT NOT NULL DEFAULT 'em_analise' CHECK (status IN ('em_analise','aprovado','recusado')),
  observacao     TEXT NULL,
  analisado_por  INTEGER NULL REFERENCES usuarios(id) ON DELETE SET NULL,
  analisado_em   TEXT NULL,
  criado_em      TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_comp_status ON comprovacoes(status);

CREATE TABLE IF NOT EXISTS vagas (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  codigo         TEXT NULL UNIQUE,
  logradouro     TEXT NOT NULL,
  numero         TEXT NULL,
  bairro         TEXT NOT NULL,
  categoria      TEXT NOT NULL CHECK (categoria IN ('PCD','IDOSO','ROTATIVO')),
  latitude       REAL NOT NULL,
  longitude      REAL NOT NULL,
  status         TEXT NOT NULL DEFAULT 'ativa' CHECK (status IN ('ativa','inativa')),
  atualizado_em  TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_vagas_bairro ON vagas(bairro);
CREATE INDEX IF NOT EXISTS idx_vagas_status ON vagas(status);

CREATE TABLE IF NOT EXISTS relatos (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  vaga_id        INTEGER NOT NULL REFERENCES vagas(id) ON DELETE CASCADE,
  usuario_id     INTEGER NULL REFERENCES usuarios(id) ON DELETE SET NULL,
  tipo           TEXT NOT NULL CHECK (tipo IN ('localizacao_incorreta','vaga_inexistente','sinalizacao_incorreta','vaga_obstruida','outro')),
  descricao      TEXT NULL,
  status         TEXT NOT NULL DEFAULT 'aberto' CHECK (status IN ('aberto','em_analise','resolvido','descartado')),
  resposta_admin TEXT NULL,
  criado_em      TEXT NOT NULL,
  atualizado_em  TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_rel_status ON relatos(status);

CREATE TABLE IF NOT EXISTS logs_acesso (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  usuario_id  INTEGER NULL,
  acao        TEXT NOT NULL,
  detalhe     TEXT NULL,
  ip          TEXT NULL,
  user_agent  TEXT NULL,
  criado_em   TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_logs_acao_data ON logs_acesso(acao, criado_em);
CREATE INDEX IF NOT EXISTS idx_logs_ip ON logs_acesso(ip);

CREATE TABLE IF NOT EXISTS api_tokens (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  usuario_id   INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
  token_hash   TEXT NOT NULL UNIQUE,
  dispositivo  TEXT NULL,
  criado_em    TEXT NOT NULL,
  usado_em     TEXT NOT NULL,
  expira_em    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS avisos (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  usuario_id  INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
  titulo      TEXT NOT NULL,
  corpo       TEXT NOT NULL,
  tela        TEXT NOT NULL DEFAULT 'perfil',
  lido_em     TEXT NULL,
  criado_em   TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_avisos_usuario ON avisos(usuario_id, lido_em);

CREATE TABLE IF NOT EXISTS inscricoes_push (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  usuario_id  INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
  tipo        TEXT NOT NULL CHECK (tipo IN ('web','fcm','apns')),
  endereco    TEXT NOT NULL UNIQUE,
  criado_em   TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS estatisticas (
  dia      TEXT NOT NULL,
  tipo     TEXT NOT NULL,
  vaga_id  INTEGER NOT NULL DEFAULT 0,
  total    INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (dia, tipo, vaga_id)
);

CREATE TABLE IF NOT EXISTS demanda_sem_vaga (
  dia    TEXT NOT NULL,
  lat    REAL NOT NULL,
  lng    REAL NOT NULL,
  nome   TEXT NOT NULL,
  total  INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (dia, lat, lng)
);

CREATE TABLE IF NOT EXISTS uso_diario (
  dia         TEXT NOT NULL,
  usuario_id  INTEGER NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
  PRIMARY KEY (dia, usuario_id)
);
