--
-- Quetoo Stats Schema
--

CREATE DATABASE IF NOT EXISTS quetoo_stats CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE quetoo_stats;

CREATE TABLE IF NOT EXISTS frags (
  id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  ts              TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  match_id        CHAR(36)             NULL,
  server_ip       VARCHAR(45)          NULL,
  server_port     SMALLINT UNSIGNED    NULL,
  server_hostname VARCHAR(255)         NULL,
  level           VARCHAR(64)      NOT NULL,
  attacker      VARCHAR(64)      NOT NULL,
  attacker_guid CHAR(64)         NOT NULL,
  attacker_ai   TINYINT(1)       NOT NULL DEFAULT 0,
  target        VARCHAR(64)      NOT NULL,
  target_guid   CHAR(64)         NOT NULL,
  target_ai     TINYINT(1)       NOT NULL DEFAULT 0,
  weapon        VARCHAR(64)          NULL,
  `mod`         INT              NOT NULL,
  `time`        INT UNSIGNED         NULL,

  PRIMARY KEY (id),
  INDEX idx_match_id      (match_id),
  INDEX idx_attacker_guid (attacker_guid),
  INDEX idx_target_guid   (target_guid),
  INDEX idx_level         (level),
  INDEX idx_weapon        (weapon),
  INDEX idx_server_ip     (server_ip),
  INDEX idx_server_host   (server_hostname),
  INDEX idx_ts            (ts),
  INDEX idx_time          (`time`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS captures (
  id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  ts              TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  match_id        CHAR(36)             NULL,
  server_ip       VARCHAR(45)          NULL,
  server_port     SMALLINT UNSIGNED    NULL,
  server_hostname VARCHAR(255)         NULL,
  level           VARCHAR(64)      NOT NULL,
  player          VARCHAR(64)      NOT NULL,
  player_guid     CHAR(64)         NOT NULL,
  player_ai       TINYINT(1)       NOT NULL DEFAULT 0,
  team            VARCHAR(64)          NULL,
  `time`          INT UNSIGNED         NULL,

  PRIMARY KEY (id),
  INDEX idx_match_id    (match_id),
  INDEX idx_player_guid (player_guid),
  INDEX idx_level       (level),
  INDEX idx_server_ip   (server_ip),
  INDEX idx_ts          (ts),
  INDEX idx_time        (`time`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS matches (
  id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  ts              TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  match_id        CHAR(36)         NOT NULL,
  server_ip       VARCHAR(45)          NULL,
  server_port     SMALLINT UNSIGNED    NULL,
  server_hostname VARCHAR(255)         NULL,
  level           VARCHAR(64)      NOT NULL,
  player          VARCHAR(64)      NOT NULL,
  player_guid     CHAR(64)         NOT NULL,
  player_ai       TINYINT(1)       NOT NULL DEFAULT 0,
  duration        INT UNSIGNED     NOT NULL,

  PRIMARY KEY (id),
  INDEX idx_match_id    (match_id),
  INDEX idx_player_guid (player_guid),
  INDEX idx_level       (level),
  INDEX idx_server_ip   (server_ip),
  INDEX idx_ts          (ts)
) ENGINE=InnoDB;

-- One row per client session. The client posts twice: once at startup with
-- its build and hardware, once at exit with the duration and map count. The
-- second post updates the row the first inserted, keyed on session_id.
--
-- token is HMAC-SHA256 (see hash_token() in config.php) of a value the client
-- already derived as md5(guid + UTC date). It therefore changes every day for
-- every player by design: this table can count distinct players per day, and
-- cannot follow one player across days. That is deliberate, and it means a
-- deletion request cannot be served from here.
CREATE TABLE IF NOT EXISTS sessions (
  id               BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  ts               TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  session_id       CHAR(36)          NOT NULL,
  token            CHAR(64)          NOT NULL,
  date             DATE              NOT NULL,
  version          VARCHAR(64)           NULL,
  build            VARCHAR(64)           NULL,
  build_number     VARCHAR(32)           NULL,
  platform         VARCHAR(32)           NULL,
  device           VARCHAR(128)          NULL,
  vendor           VARCHAR(64)           NULL,
  renderer         VARCHAR(32)           NULL,
  cpu_cores        SMALLINT UNSIGNED     NULL,
  system_ram_mb    INT UNSIGNED          NULL,
  duration         INT UNSIGNED          NULL,
  maps             INT UNSIGNED          NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uk_session_id (session_id),
  INDEX idx_date       (date),
  INDEX idx_token_date (token, date)
) ENGINE=InnoDB;

-- Per-address request counter for POST /api/sessions, which has no other gate.
-- ip_hash is HMAC-SHA256 of the client address; the raw address is never
-- stored, here or anywhere else. window_start is DATETIME rather than
-- TIMESTAMP because the route compares it against UTC_TIMESTAMP(): a
-- TIMESTAMP is converted through the session time zone on write and read,
-- which misbehaves across a DST transition.
CREATE TABLE IF NOT EXISTS analytics_rate (
  ip_hash      CHAR(64)     NOT NULL,
  window_start DATETIME     NOT NULL DEFAULT UTC_TIMESTAMP(),
  requests     INT UNSIGNED NOT NULL DEFAULT 0,

  PRIMARY KEY (ip_hash)
) ENGINE=InnoDB;

-- Audit trail for GUID merges performed via maintenance/merge_guid.php, e.g.
-- when a player loses their quetoo.cfg and their prior stats need to be
-- folded onto their new client-generated GUID.
CREATE TABLE IF NOT EXISTS guid_merges (
  id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  ts           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  old_guid     CHAR(64)         NOT NULL,
  new_guid     CHAR(64)         NOT NULL,
  rows_updated INT UNSIGNED     NOT NULL,
  note         VARCHAR(255)         NULL,

  PRIMARY KEY (id),
  INDEX idx_old_guid (old_guid),
  INDEX idx_new_guid (new_guid),
  INDEX idx_ts        (ts)
) ENGINE=InnoDB;
