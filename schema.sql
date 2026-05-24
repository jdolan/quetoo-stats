--
-- Quetoo Stats Schema
--

CREATE DATABASE IF NOT EXISTS quetoo_stats CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE quetoo_stats;

CREATE TABLE IF NOT EXISTS frags (
  id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  ts            TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  level         VARCHAR(64)      NOT NULL,
  attacker      VARCHAR(64)      NOT NULL,
  attacker_guid CHAR(36)         NOT NULL,
  target        VARCHAR(64)      NOT NULL,
  target_guid   CHAR(36)         NOT NULL,
  weapon        VARCHAR(64)          NULL,
  `mod`         INT              NOT NULL,
  damage        SMALLINT         NOT NULL,
  `time`        INT UNSIGNED         NULL,

  PRIMARY KEY (id),
  INDEX idx_attacker_guid (attacker_guid),
  INDEX idx_target_guid   (target_guid),
  INDEX idx_level         (level),
  INDEX idx_weapon        (weapon),
  INDEX idx_ts            (ts)
) ENGINE=InnoDB;
