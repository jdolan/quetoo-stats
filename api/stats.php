<?php
/**
 * GET /api/stats
 *
 * Global leaderboard — counts frags grouped by player, ordered by frags desc.
 *
 * Query parameters:
 *   weapon  string   Filter by weapon name (e.g. "railgun")
 *   mod     int      Filter by means-of-death value
 *   level   string   Filter by map name
 *   ai      int      0 = exclude bot frags (default), 1 = include all
 *   limit   int      Max rows to return (default 25, max 200)
 *
 * GET /api/stats/<guid>
 *
 * Player deep stats — kills/deaths by weapon and by player.
 * <guid> is the HMAC-SHA256 hashed form of the player's raw GUID.
 *
 * Same query parameters as above.
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['error' => 'Method Not Allowed']);
  exit;
}

// ------------------------------------------------------------------
// Common filter helpers
// ------------------------------------------------------------------

function build_filters(array $get): array {
  $where  = [];
  $params = [];

  if (!empty($get['weapon'])) {
    $where[]           = 'weapon = :weapon';
    $params[':weapon'] = substr($get['weapon'], 0, 64);
  }
  if (isset($get['mod']) && $get['mod'] !== '') {
    $where[]        = '`mod` = :mod';
    $params[':mod'] = (int) $get['mod'];
  }
  if (!empty($get['level'])) {
    $where[]          = 'level = :level';
    $params[':level'] = substr($get['level'], 0, 64);
  }

  // ai=0 (default): exclude frags where the attacker or target is a bot
  $ai = isset($get['ai']) ? (int) $get['ai'] : 0;
  if ($ai === 0) {
    $where[] = 'attacker_ai = 0 AND target_ai = 0';
  }

  return [$where, $params];
}

function limit_param(array $get): int {
  $limit = isset($get['limit']) ? (int) $get['limit'] : 25;
  return max(1, min(200, $limit));
}

// ------------------------------------------------------------------
// Routing
// ------------------------------------------------------------------

$pdo  = db_connect();
$guid = $_GET['guid'] ?? null;  // set by .htaccess rewrite for /api/stats/<guid>

if ($guid !== null) {
  player_stats($pdo, $guid, $_GET);
} else {
  global_leaderboard($pdo, $_GET);
}

// ------------------------------------------------------------------
// Global leaderboard
// ------------------------------------------------------------------

function global_leaderboard(PDO $pdo, array $get): void {
  [$where, $params] = build_filters($get);
  $limit = limit_param($get);

  $base_clause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

  // Name filter is applied as a WHERE on the outer (ranked) subquery so that
  // rank reflects the global position, not the filtered position.
  $name_clause  = '';
  $name_params  = [];
  if (!empty($get['name'])) {
    $name_clause           = 'WHERE name LIKE :name';
    $name_params[':name']  = '%' . substr($get['name'], 0, 64) . '%';
  }

  // Compute rank via window function before name filter is applied.
  $stmt = $pdo->prepare("
    SELECT *
    FROM (
      SELECT
        attacker_guid               AS guid,
        attacker                    AS name,
        COUNT(*)                    AS frags,
        SUM(damage)                 AS damage,
        (MAX(`time`) - MIN(`time`)) AS time_played,
        RANK() OVER (ORDER BY COUNT(*) DESC) AS rank
      FROM frags
      $base_clause
      GROUP BY attacker_guid, attacker
    ) ranked
    $name_clause
    ORDER BY rank
    LIMIT $limit
  ");
  $stmt->execute(array_merge($params, $name_params));
  $rows = [];
  foreach ($stmt->fetchAll() as $r) {
    $rows[$r['guid']] = array_merge($r, ['deaths' => 0]);
  }

  // Deaths per target (same base filters, no name restriction)
  $stmt = $pdo->prepare("
    SELECT target_guid, COUNT(*) AS deaths
    FROM frags
    $base_clause
    GROUP BY target_guid
  ");
  $stmt->execute($params);
  foreach ($stmt->fetchAll() as $r) {
    if (isset($rows[$r['target_guid']])) {
      $rows[$r['target_guid']]['deaths'] = (int) $r['deaths'];
    }
  }

  echo json_encode(array_values($rows));
}

// ------------------------------------------------------------------
// Player detail
// ------------------------------------------------------------------

function player_stats(PDO $pdo, string $guid, array $get): void {
  [$where, $params] = build_filters($get);
  $limit = limit_param($get);

  // Validate: 64-char hex
  if (!preg_match('/^[a-f0-9]{64}$/', $guid)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid guid']);
    return;
  }

  $params[':guid'] = $guid;

  $attacker_clause = $where
    ? ('WHERE attacker_guid = :guid AND ' . implode(' AND ', $where))
    : 'WHERE attacker_guid = :guid';

  $target_clause = $where
    ? ('WHERE target_guid = :guid AND ' . implode(' AND ', $where))
    : 'WHERE target_guid = :guid';

  // Kills by weapon
  $stmt = $pdo->prepare("
    SELECT weapon, COUNT(*) AS frags, SUM(damage) AS damage
    FROM frags $attacker_clause
    GROUP BY weapon ORDER BY frags DESC LIMIT $limit
  ");
  $stmt->execute($params);
  $kills_by_weapon = $stmt->fetchAll();

  // Kills by target
  $stmt = $pdo->prepare("
    SELECT target AS name, target_guid AS guid, COUNT(*) AS frags, SUM(damage) AS damage
    FROM frags $attacker_clause
    GROUP BY target_guid, target ORDER BY frags DESC LIMIT $limit
  ");
  $stmt->execute($params);
  $kills_by_target = $stmt->fetchAll();

  // Deaths by weapon
  $stmt = $pdo->prepare("
    SELECT weapon, COUNT(*) AS deaths
    FROM frags $target_clause
    GROUP BY weapon ORDER BY deaths DESC LIMIT $limit
  ");
  $stmt->execute($params);
  $deaths_by_weapon = $stmt->fetchAll();

  // Deaths by attacker
  $stmt = $pdo->prepare("
    SELECT attacker AS name, attacker_guid AS guid, COUNT(*) AS deaths
    FROM frags $target_clause
    GROUP BY attacker_guid, attacker ORDER BY deaths DESC LIMIT $limit
  ");
  $stmt->execute($params);
  $deaths_by_attacker = $stmt->fetchAll();

  // Kills by level
  $stmt = $pdo->prepare("
    SELECT level, COUNT(*) AS frags, SUM(damage) AS damage
    FROM frags $attacker_clause
    GROUP BY level ORDER BY frags DESC LIMIT $limit
  ");
  $stmt->execute($params);
  $kills_by_level = $stmt->fetchAll();

  // Deaths by level
  $stmt = $pdo->prepare("
    SELECT level, COUNT(*) AS deaths
    FROM frags $target_clause
    GROUP BY level ORDER BY deaths DESC LIMIT $limit
  ");
  $stmt->execute($params);
  $deaths_by_level = $stmt->fetchAll();

  // Summary totals + global rank
  $stmt = $pdo->prepare("
    SELECT COUNT(*) AS frags, SUM(damage) AS damage,
           (MAX(`time`) - MIN(`time`)) AS time_played
    FROM frags $attacker_clause
  ");
  $stmt->execute($params);
  $totals = $stmt->fetch();

  // Rank: how many players have more frags (using same base filters, no guid filter)
  [$rank_where, $rank_params] = build_filters($get);
  $rank_base = $rank_where ? ('WHERE ' . implode(' AND ', $rank_where)) : '';
  $rank_stmt = $pdo->prepare("
    SELECT ranked.rank FROM (
      SELECT attacker_guid,
             RANK() OVER (ORDER BY COUNT(*) DESC) AS rank
      FROM frags
      $rank_base
      GROUP BY attacker_guid
    ) ranked
    WHERE ranked.attacker_guid = :rank_guid
  ");
  $rank_params[':rank_guid'] = $guid;
  $rank_stmt->execute($rank_params);
  $rank_row = $rank_stmt->fetch();
  $rank = $rank_row ? (int) $rank_row['rank'] : null;

  echo json_encode([
    'guid'               => $guid,
    'rank'               => $rank,
    'frags'              => (int) $totals['frags'],
    'damage'             => (int) $totals['damage'],
    'time_played'        => (int) $totals['time_played'],
    'kills_by_weapon'    => $kills_by_weapon,
    'kills_by_target'    => $kills_by_target,
    'kills_by_level'     => $kills_by_level,
    'deaths_by_weapon'   => $deaths_by_weapon,
    'deaths_by_attacker' => $deaths_by_attacker,
    'deaths_by_level'    => $deaths_by_level,
  ]);
}
