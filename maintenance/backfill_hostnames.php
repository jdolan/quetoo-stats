<?php
/**
 * Back-fill server_hostname for rows that never resolved to a real name.
 *
 * Rows written while the hostname lookup was failing hold the raw server_ip,
 * so those are re-resolved here along with NULL and empty values. Servers that
 * are not currently registered with the master cannot be resolved, and their
 * rows are left untouched rather than rewritten with the IP again.
 *
 *   sudo php maintenance/backfill_hostnames.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/common.php';

$pdo = db_connect();

$tables = ['frags', 'captures', 'matches'];

$resolved = [];

foreach ($tables as $table) {
  $ips = $pdo->query(
    "SELECT DISTINCT server_ip FROM {$table}
     WHERE server_ip IS NOT NULL
       AND (server_hostname IS NULL OR server_hostname = '' OR server_hostname = server_ip)"
  )->fetchAll(PDO::FETCH_COLUMN);

  if (empty($ips)) {
    echo "{$table}: nothing to backfill.\n";
    continue;
  }

  $stmt = $pdo->prepare(
    "UPDATE {$table} SET server_hostname = :hostname
     WHERE server_ip = :ip
       AND (server_hostname IS NULL OR server_hostname = '' OR server_hostname = server_ip)"
  );

  echo "{$table}:\n";

  foreach ($ips as $ip) {
    if (!array_key_exists($ip, $resolved)) {
      $hostname = server_hostname($ip);
      $resolved[$ip] = $hostname === $ip ? null : $hostname;
    }

    if ($resolved[$ip] === null) {
      echo "  {$ip} -> unresolved, left as is\n";
      continue;
    }

    $stmt->execute([':hostname' => $resolved[$ip], ':ip' => $ip]);
    echo "  {$ip} -> {$resolved[$ip]} ({$stmt->rowCount()} rows)\n";
  }
}

echo "Done.\n";
