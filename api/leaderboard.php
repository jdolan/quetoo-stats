<?php
/**
 * GET /api/leaderboard
 *
 * Returns the all-time top fraggers as JSON, suitable for the website.
 *
 * Optional query params:
 *   ?limit=25        Number of players to return (default 25, max 100)
 *   ?level=dm_quetoo Filter to a specific map
 *   ?weapon=railgun  Filter to a specific weapon
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['error' => 'Method Not Allowed']);
  exit;
}

$limit  = min(100, max(1, (int) ($_GET['limit']  ?? 25)));
$level  = $_GET['level']  ?? null;
$weapon = $_GET['weapon'] ?? null;

$pdo = db_connect();

$where  = [];
$params = [];

if ($level) {
  $where[]          = 'level = :level';
  $params[':level'] = $level;
}
if ($weapon) {
  $where[]           = 'weapon = :weapon';
  $params[':weapon'] = $weapon;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
  SELECT
    attacker                          AS name,
    attacker_guid                     AS guid,
    COUNT(*)                          AS frags,
    SUM(damage)                       AS damage,
    (SELECT COUNT(*) FROM frags t2
       WHERE t2.target_guid = f.attacker_guid) AS deaths
  FROM frags f
  $where_sql
  GROUP BY attacker_guid, attacker
  ORDER BY frags DESC
  LIMIT :limit
";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
  $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
