<?php
/**
 * POST /api/frags
 *
 * Accepts a JSON array of frag events from a Quetoo dedicated server and
 * inserts them into the frags table.
 *
 * Expected payload:
 * [
 *   {
 *     "level":         "dm_quetoo",
 *     "attacker":      "PlayerA",
 *     "attacker_guid": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
 *     "target":        "PlayerB",
 *     "target_guid":   "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
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

$stmt = $pdo->prepare(
  'INSERT INTO frags (level, attacker, attacker_guid, target, target_guid, weapon, `mod`, damage, `time`)
   VALUES (:level, :attacker, :attacker_guid, :target, :target_guid, :weapon, :mod, :damage, :time)'
);

$pdo->beginTransaction();

$inserted = 0;
foreach ($frags as $f) {
  if (!isset($f['level'], $f['attacker'], $f['attacker_guid'],
               $f['target'], $f['target_guid'], $f['mod'], $f['damage'])) {
    continue;
  }

  $stmt->execute([
    ':level'         => substr($f['level'],         0, 64),
    ':attacker'      => substr($f['attacker'],      0, 64),
    ':attacker_guid' => substr($f['attacker_guid'], 0, 36),
    ':target'        => substr($f['target'],        0, 64),
    ':target_guid'   => substr($f['target_guid'],   0, 36),
    ':weapon'        => isset($f['weapon']) ? substr($f['weapon'], 0, 64) : null,
    ':mod'           => (int) $f['mod'],
    ':damage'        => (int) $f['damage'],
    ':time'          => isset($f['time']) ? (int) $f['time'] : null,
  ]);
  $inserted++;
}

$pdo->commit();

echo json_encode(['inserted' => $inserted]);
