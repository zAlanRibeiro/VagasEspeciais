-- Vagas Especiais Niterói — schema MySQL/MariaDB
-- Uso: mysql -u root -p < database/schema.sql

CREATE DATABASE IF NOT EXISTS vagas_especiais
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE vagas_especiais;

CREATE TABLE IF NOT EXISTS usuarios (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome            VARCHAR(150) NOT NULL,
  email           VARCHAR(190) NOT NULL UNIQUE,
  senha_hash      VARCHAR(255) NOT NULL,
  papel           ENUM('usuario','admin') NOT NULL DEFAULT 'usuario',
  suspenso        TINYINT(1) NOT NULL DEFAULT 0,   -- conta suspensa pela administração: sem acesso ao mapa
  aceite_termos_em DATETIME NULL,
  criado_em       DATETIME NOT NULL,
  atualizado_em   DATETIME NOT NULL
) ENGINE=InnoDB;

-- Perfis da pessoa (pode ter mais de um). Cada perfil aprovado libera um tipo de vaga no mapa.
-- ROTATIVO (motorista em geral) vale na hora; PCD e IDOSO passam pela comprovação:
-- pendente (sem documento) → em_analise → aprovado | recusado
CREATE TABLE IF NOT EXISTS perfis (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id      INT UNSIGNED NOT NULL,
  tipo            ENUM('PCD','IDOSO','ROTATIVO') NOT NULL,
  status          ENUM('pendente','em_analise','aprovado','recusado') NOT NULL DEFAULT 'pendente',
  motivo_recusa   VARCHAR(500) NULL,
  criado_em       DATETIME NOT NULL,
  atualizado_em   DATETIME NOT NULL,
  UNIQUE KEY uq_perfil_usuario_tipo (usuario_id, tipo),
  INDEX idx_perfis_status (status),
  CONSTRAINT fk_perfil_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS comprovacoes (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id     INT UNSIGNED NOT NULL,
  perfil         ENUM('PCD','IDOSO') NOT NULL,  -- perfil que o documento comprova
  arquivo        VARCHAR(100) NOT NULL,      -- nome aleatório em storage/documentos
  nome_original  VARCHAR(255) NOT NULL,
  mime           VARCHAR(50)  NOT NULL,
  tamanho        INT UNSIGNED NOT NULL,
  status         ENUM('em_analise','aprovado','recusado') NOT NULL DEFAULT 'em_analise',
  observacao     VARCHAR(500) NULL,
  analisado_por  INT UNSIGNED NULL,
  analisado_em   DATETIME NULL,
  criado_em      DATETIME NOT NULL,
  CONSTRAINT fk_comp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_comp_admin   FOREIGN KEY (analisado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_comp_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS vagas (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo         VARCHAR(50)  NULL UNIQUE,   -- ID da base Nittrans
  logradouro     VARCHAR(200) NOT NULL,
  numero         VARCHAR(30)  NULL,
  bairro         VARCHAR(100) NOT NULL,
  categoria      ENUM('PCD','IDOSO','ROTATIVO') NOT NULL,
  latitude       DECIMAL(10,7) NOT NULL,
  longitude      DECIMAL(10,7) NOT NULL,
  status         ENUM('ativa','inativa') NOT NULL DEFAULT 'ativa',
  atualizado_em  DATETIME NOT NULL,
  INDEX idx_vagas_bairro (bairro),
  INDEX idx_vagas_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS relatos (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vaga_id        INT UNSIGNED NOT NULL,
  usuario_id     INT UNSIGNED NULL,
  tipo           ENUM('localizacao_incorreta','vaga_inexistente','sinalizacao_incorreta','vaga_obstruida','outro') NOT NULL,
  descricao      VARCHAR(1000) NULL,
  status         ENUM('aberto','em_analise','resolvido','descartado') NOT NULL DEFAULT 'aberto',
  resposta_admin VARCHAR(1000) NULL,
  criado_em      DATETIME NOT NULL,
  atualizado_em  DATETIME NOT NULL,
  CONSTRAINT fk_rel_vaga    FOREIGN KEY (vaga_id) REFERENCES vagas(id) ON DELETE CASCADE,
  CONSTRAINT fk_rel_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_rel_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS logs_acesso (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NULL,
  acao        VARCHAR(50)  NOT NULL,
  detalhe     VARCHAR(500) NULL,
  ip          VARCHAR(45)  NULL,
  user_agent  VARCHAR(255) NULL,
  criado_em   DATETIME NOT NULL,
  INDEX idx_logs_acao_data (acao, criado_em),
  INDEX idx_logs_ip (ip)
) ENGINE=InnoDB;

-- Tokens de acesso do app móvel (guardamos só o hash SHA-256 do token).
CREATE TABLE IF NOT EXISTS api_tokens (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id   INT UNSIGNED NOT NULL,
  token_hash   CHAR(64) NOT NULL UNIQUE,
  dispositivo  VARCHAR(100) NULL,
  criado_em    DATETIME NOT NULL,
  usado_em     DATETIME NOT NULL,
  expira_em    DATETIME NOT NULL,
  CONSTRAINT fk_tok_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Avisos ao usuário (central de avisos + notificações no celular)
CREATE TABLE IF NOT EXISTS avisos (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  titulo      VARCHAR(120) NOT NULL,
  corpo       VARCHAR(500) NOT NULL,
  tela        VARCHAR(20)  NOT NULL DEFAULT 'perfil',  -- para onde o aviso leva: perfil | mapa
  lido_em     DATETIME NULL,
  criado_em   DATETIME NOT NULL,
  CONSTRAINT fk_aviso_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_avisos_usuario (usuario_id, lido_em)
) ENGINE=InnoDB;

-- Aparelhos que recebem notificação: web (navegador/PWA), fcm (Android), apns (iPhone)
CREATE TABLE IF NOT EXISTS inscricoes_push (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  tipo        ENUM('web','fcm','apns') NOT NULL,
  endereco    VARCHAR(512) NOT NULL,                   -- endpoint (web) ou token do aparelho
  criado_em   DATETIME NOT NULL,
  CONSTRAINT fk_push_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  UNIQUE KEY uq_push_endereco (endereco(255))
) ENGINE=InnoDB;

-- Estatísticas de uso, agregadas por dia (sem identificar quem fez)
CREATE TABLE IF NOT EXISTS estatisticas (
  dia      DATE NOT NULL,
  tipo     VARCHAR(20) NOT NULL,          -- vaga_vista | rota | navegacao | chegada | waze | gmaps | busca | busca_sem_vaga
  vaga_id  INT UNSIGNED NOT NULL DEFAULT 0,  -- 0 quando o evento não é de uma vaga
  total    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (dia, tipo, vaga_id)
) ENGINE=InnoDB;

-- Destinos buscados sem vaga a menos de 1 km (posição arredondada ~100 m): onde falta vaga
CREATE TABLE IF NOT EXISTS demanda_sem_vaga (
  dia    DATE NOT NULL,
  lat    DECIMAL(7,3) NOT NULL,
  lng    DECIMAL(7,3) NOT NULL,
  nome   VARCHAR(120) NOT NULL,
  total  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (dia, lat, lng)
) ENGINE=InnoDB;

-- Dias em que cada usuário usou o mapa (só para contar usuários ativos; apagado após 90 dias)
CREATE TABLE IF NOT EXISTS uso_diario (
  dia         DATE NOT NULL,
  usuario_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (dia, usuario_id),
  CONSTRAINT fk_uso_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;
