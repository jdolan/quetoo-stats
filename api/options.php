<?php
/**
 * GET /api/options
 *
 * Returns the distinct server hostnames, map names, and CTF teams present in
 * the frags and captures tables, for use in populating filter dropdowns on
 * the stats UI.
 *
 * Response:
 * {
 *   "servers": ["giblets.quetoo.org", ...],
 *   "levels":  ["dm_quetoo", ...],
 *   "teams":   ["red", "blue", ...]
 * }
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['error' => 'Method Not Allowed']);
  exit;
}

$pdo = db_connect();

$servers = $pdo->query(
  "SELECT DISTINCT server_hostname FROM frags
   WHERE server_hostname IS NOT NULL AND server_hostname != ''
   ORDER BY server_hostname"
)->fetchAll(PDO::FETCH_COLUMN);

$levels = $pdo->query(
  "SELECT DISTINCT level FROM frags
   ORDER BY level"
)->fetchAll(PDO::FETCH_COLUMN);

$teams = $pdo->query(
  "SELECT DISTINCT team FROM captures
   WHERE team IS NOT NULL AND team != ''
   ORDER BY team"
)->fetchAll(PDO::FETCH_COLUMN);

echo json_encode([
  'servers' => array_values($servers),
  'levels'  => array_values($levels),
  'teams'   => array_values($teams),
]);
