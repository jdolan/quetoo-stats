<?php
/**
 * POST /api/frags
 *
 * Accepts a JSON array of frag events from a Quetoo dedicated server and
 * inserts them into the frags table. Raw GUIDs are hashed with a server-side
 * HMAC-SHA256 salt before storage so they are never exposed via the API.
 *
 * Expected payload:
 * [
 *   {
 *     "level":         "dm_quetoo",
 *     "attacker":      "PlayerA",
 *     "attacker_guid": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
 *     "attacker_ai":   false,
 *     "target":        "PlayerB",
 *     "target_guid":   "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
 *     "target_ai":     false,
 *     "weapon":        "railgun",
 *     "mod":           12,
 *     "damage":        100,
 *     "time":          1716000000
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

$body = file_get_contents('php://input');
$frags = json_decode($body, true);

if (!is_array($frags) || empty($frags)) {
  http_response_code(400);
  echo json_encode(['error' => 'Expected a non-empty JSON array']);
  exit;
}

$pdo = db_connect();

// Generate a UUID v4 for this batch — all frags from one POST share a match_id.
$match_id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
  mt_rand(0, 0xffff), mt_rand(0, 0xffff),
  mt_rand(0, 0xffff),
  mt_rand(0, 0x0fff) | 0x4000,
  mt_rand(0, 0x3fff) | 0x8000,
  mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);

$stmt = $pdo->prepare(
  'INSERT INTO frags (match_id, server_ip, server_hostname, level, attacker, attacker_guid, attacker_ai, target, target_guid, target_ai, weapon, `mod`, damage, `time`)
   VALUES (:match_id, :server_ip, :server_hostname, :level, :attacker, :attacker_guid, :attacker_ai, :target, :target_guid, :target_ai, :weapon, :mod, :damage, :time)'
);

$pdo->beginTransaction();

$server_ip = $_SERVER['REMOTE_ADDR'] ?? null;
$inserted = 0;
foreach ($frags as $f) {
  if (!isset($f['level'], $f['attacker'], $f['attacker_guid'],
               $f['target'], $f['target_guid'], $f['mod'], $f['damage'])) {
    continue;
  }

  $stmt->execute([
    ':match_id'        => $match_id,
    ':server_ip'       => $server_ip,
    ':server_hostname' => server_hostname($server_ip),
    ':level'           => substr($f['level'],    0, 64),
    ':attacker'      => substr($f['attacker'], 0, 64),
    ':attacker_guid' => hash_guid($f['attacker_guid']),
    ':attacker_ai'   => !empty($f['attacker_ai']) ? 1 : 0,
    ':target'        => substr($f['target'],   0, 64),
    ':target_guid'   => hash_guid($f['target_guid']),
    ':target_ai'     => !empty($f['target_ai']) ? 1 : 0,
    ':weapon'        => isset($f['weapon']) ? substr($f['weapon'], 0, 64) : null,
    ':mod'           => (int) $f['mod'],
    ':damage'        => (int) $f['damage'],
    ':time'          => isset($f['time']) ? (int) $f['time'] : null,
  ]);
  $inserted++;
}

$pdo->commit();

echo json_encode(['inserted' => $inserted, 'match_id' => $match_id]);
