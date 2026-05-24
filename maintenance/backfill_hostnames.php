<?php
/**
 * One-time script: back-fill server_hostname for existing frag rows.
 *
 * Run once from the CLI after deploying the server_hostname column:
 *   sudo php maintenance/backfill_hostnames.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/servers.php';

$pdo = db_connect();

$ips = $pdo->query(
  "SELECT DISTINCT server_ip FROM frags
   WHERE (server_hostname IS NULL OR server_hostname = '')
     AND server_ip IS NOT NULL"
)->fetchAll(PDO::FETCH_COLUMN);

if (empty($ips)) {
  echo "Nothing to backfill.\n";
  exit(0);
}

$stmt = $pdo->prepare(
  "UPDATE frags SET server_hostname = :hostname WHERE server_ip = :ip"
);

foreach ($ips as $ip) {
  $hostname = server_hostname($ip);
  $stmt->execute([':hostname' => $hostname, ':ip' => $ip]);
  $n = $stmt->rowCount();
  echo "  $ip -> $hostname ($n rows)\n";
}

echo "Done.\n";
