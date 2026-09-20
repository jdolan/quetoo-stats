--
-- Migration 006: Add the sessions and analytics_rate tables.
--
-- Quetoo game clients now post an anonymous session record at startup and at
-- exit (see api/sessions.php). This answers how many people play per day,
-- what they run it on, and how quickly they pick up a release - none of which
-- the master server can tell us, because it only sees public listed servers.
--
-- Unlike frags and captures, these rows cannot be tied back to a person. The
-- client identifier rotates every UTC day before it is ever sent, so there is
-- no stable key here and no way to serve a deletion request.
--
-- Run once against the production database:
--   mysql -u <user> -p quetoo_stats < migrate/006_add_sessions.sql
--

USE quetoo_stats;

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
