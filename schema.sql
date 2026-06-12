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
