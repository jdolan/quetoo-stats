<?php
/**
 * POST /api/captures
 *
 * Accepts a JSON array of capture events from a Quetoo dedicated server and
 * inserts them into the captures table. Raw GUIDs are hashed with a server-side
 * HMAC-SHA256 salt before storage so they are never exposed via the API.
 *
 * Expected payload:
 * [
 *   {
 *     "level":      "ctf_quetoo",
 *     "player":     "PlayerA",
 *     "player_guid": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
 *     "player_ai":  false,
 *     "team":       "red",
 *     "time":       1716000000
 *   },
 *   ...
 * ]
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/servers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'Method Not Allowed']);
  exit;
}

if (!is_registered_server($_SERVER['REMOTE_ADDR'])) {
  http_response_code(403);
  echo json_encode(['error' => 'Forbidden']);
  exit;
}

$body     = file_get_contents('php://input');
$captures = json_decode($body, true);

if (!is_array($captures) || empty($captures)) {
  http_response_code(400);
  echo json_encode(['error' => 'Expected a non-empty JSON array']);
  exit;
}

$pdo = db_connect();

// Generate a UUID v4 for this batch — all captures from one POST share a match_id.
$match_id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
  mt_rand(0, 0xffff), mt_rand(0, 0xffff),
  mt_rand(0, 0xffff),
  mt_rand(0, 0x0fff) | 0x4000,
  mt_rand(0, 0x3fff) | 0x8000,
  mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);

$stmt = $pdo->prepare(
  'INSERT INTO captures (match_id, server_ip, server_hostname, level, player, player_guid, player_ai, team, `time`)
   VALUES (:match_id, :server_ip, :server_hostname, :level, :player, :player_guid, :player_ai, :team, :time)'
);

$pdo->beginTransaction();

$server_ip = $_SERVER['REMOTE_ADDR'] ?? null;
$inserted  = 0;
foreach ($captures as $c) {
  if (!isset($c['level'], $c['player'], $c['player_guid'])) {
    continue;
  }

  $stmt->execute([
    ':match_id'        => $match_id,
    ':server_ip'       => $server_ip,
    ':server_hostname' => server_hostname($server_ip),
    ':level'           => substr($c['level'],       0, 64),
    ':player'          => substr($c['player'],      0, 64),
    ':player_guid'     => hash_guid($c['player_guid']),
    ':player_ai'       => !empty($c['player_ai']) ? 1 : 0,
    ':team'            => isset($c['team']) ? substr($c['team'], 0, 64) : null,
    ':time'            => isset($c['time']) ? (int) $c['time'] : null,
  ]);
  $inserted++;
}

$pdo->commit();

echo json_encode(['inserted' => $inserted, 'match_id' => $match_id]);
