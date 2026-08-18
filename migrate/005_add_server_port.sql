--
-- Adds server_port to frags/captures/matches.
--
-- Newer Quetoo dedicated servers self-report their listening port (and
-- hostname) via the X-Quetoo-Port / X-Quetoo-Hostname request headers on
-- POST /api/frags and POST /api/captures (see api/common.php's
-- reported_port()/reported_hostname()). This lets rows be unambiguously
-- attributed to a specific server instance even when several instances
-- share one public IP on different ports, which server_ip alone cannot
-- express. Older clients that don't send the header simply leave this
-- column NULL.
--

USE quetoo_stats;

ALTER TABLE frags
  ADD COLUMN server_port SMALLINT UNSIGNED NULL AFTER server_ip;

ALTER TABLE captures
  ADD COLUMN server_port SMALLINT UNSIGNED NULL AFTER server_ip;

ALTER TABLE matches
  ADD COLUMN server_port SMALLINT UNSIGNED NULL AFTER server_ip;
