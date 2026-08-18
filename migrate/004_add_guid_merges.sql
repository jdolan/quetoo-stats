--
-- Migration 004: Add guid_merges audit table.
--
-- Tracks GUID merges performed via maintenance/merge_guid.php. Used when a
-- player loses their quetoo.cfg (and thus their `guid` cvar), gets a new
-- client-generated UUID, and wants their prior frags/captures/matches
-- history folded onto the new identity.
--
-- Run once against the production database:
--   mysql -u <user> -p quetoo_stats < migrate/004_add_guid_merges.sql
--

USE quetoo_stats;

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
