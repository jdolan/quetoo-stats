--
-- Migration 001: Add matches table and backfill from frags and captures.
--
-- The matches table stores one row per player per match batch, with duration
-- computed as MAX(time) - MIN(time) in seconds over all frag/capture events
-- the player appears in for that batch. Batches with fewer than two timestamped
-- events per player are skipped since no meaningful interval can be derived.
--
-- Run once against the production database:
--   mysql -u <user> -p quetoo_stats < migrate/001_add_matches.sql
--

USE quetoo_stats;

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

-- Backfill from frags: union attacker and target roles so every player who
-- participated (as killer or victim) contributes to their own time window.
INSERT INTO matches (match_id, server_ip, server_hostname, level, player, player_guid, player_ai, duration)
SELECT
  match_id,
  server_ip,
  server_hostname,
  level,
  player,
  player_guid,
  player_ai,
  MAX(`time`) - MIN(`time`) AS duration
FROM (
  SELECT match_id, server_ip, server_hostname, level,
         attacker AS player, attacker_guid AS player_guid, attacker_ai AS player_ai, `time`
  FROM frags
  WHERE match_id IS NOT NULL AND `time` IS NOT NULL
  UNION ALL
  SELECT match_id, server_ip, server_hostname, level,
         target, target_guid, target_ai, `time`
  FROM frags
  WHERE match_id IS NOT NULL AND `time` IS NOT NULL
) combined
GROUP BY match_id, player_guid, player, player_ai, level
HAVING COUNT(*) >= 2 AND MAX(`time`) > MIN(`time`);

-- Backfill from captures (separate match_ids, so no overlap with frags rows).
INSERT INTO matches (match_id, server_ip, server_hostname, level, player, player_guid, player_ai, duration)
SELECT
  match_id,
  server_ip,
  server_hostname,
  level,
  player,
  player_guid,
  player_ai,
  MAX(`time`) - MIN(`time`) AS duration
FROM captures
WHERE match_id IS NOT NULL AND `time` IS NOT NULL
GROUP BY match_id, player_guid, player, player_ai, level
HAVING COUNT(*) >= 2 AND MAX(`time`) > MIN(`time`);
