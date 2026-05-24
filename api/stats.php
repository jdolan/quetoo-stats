<?php
/**
 * GET /api/stats
 *
 * Global leaderboard — counts frags grouped by player, ordered by frags desc.
 *
 * Query parameters:
 *   match_id string   Filter to a specific match UUID (returned by POST /api/frags)
 *   from    string   Start date (YYYY-MM-DD, inclusive)
 *   to      string   End date   (YYYY-MM-DD, inclusive)
 *   weapon  string   Filter by weapon name (e.g. "railgun")
 *   mod     int      Filter by means-of-death value
 *   level   string   Filter by map name
 *   ai      int      0 = human attackers only, bots can be victims (default), 1 = include all
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

/**
 * @param string $ai_side  'attacker' or 'target' — which side the ai filter applies to.
 *                         Kill queries pass 'attacker' (human killers, bots can be victims).
 *                         Death queries pass 'target' (human victims, bots can be killers).
 */
function build_filters(array $get, string $ai_side = 'target'): array {
  $where  = [];
  $params = [];

  if (!empty($get['match_id'])) {
    // Validate UUID v4 format before using as a filter
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $get['match_id'])) {
      $where[]              = 'match_id = :match_id';
      $params[':match_id']  = $get['match_id'];
    }
  }
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

  // Date range on the frag time column (YYYY-MM-DD → Unix timestamps)
  if (!empty($get['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $get['from'])) {
    $where[]         = '`time` >= :from';
    $params[':from'] = (int) strtotime($get['from']);
  }
  if (!empty($get['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $get['to'])) {
    $where[]       = '`time` <= :to';
    $params[':to'] = (int) strtotime($get['to'] . ' 23:59:59');
  }

  // ai=0 (default): only the relevant side must be human.
  // Kills: attacker must be human (bots can be victims).
  // Deaths: target must be human (bots can be killers).
  $ai = isset($get['ai']) ? (int) $get['ai'] : 0;
  if ($ai === 0) {
    $where[] = $ai_side . '_ai = 0';
  }

  return [$where, $params];
}

/**
 * Returns WHERE conditions appropriate for kill (attacker-side) queries.
 * Suicides are excluded from kills but still count as deaths.
 */
function build_kill_filters(array $get): array {
  [$where, $params] = build_filters($get, 'attacker');
  $where[] = 'attacker_guid != target_guid';
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
  [$kills_where, $kills_params]   = build_kill_filters($get);
  [$deaths_where, $deaths_params] = build_filters($get);
  $limit = limit_param($get);

  $kills_base  = $kills_where  ? ('WHERE ' . implode(' AND ', $kills_where))  : '';
  $deaths_base = $deaths_where ? ('WHERE ' . implode(' AND ', $deaths_where)) : '';

  // Name filter is applied as a WHERE on the outer (ranked) subquery so that
  // rank reflects the global position, not the filtered position.
  $name_clause  = '';
  $name_params  = [];
  if (!empty($get['name'])) {
    $name_clause           = 'WHERE name LIKE :name';
    $name_params[':name']  = '%' . substr($get['name'], 0, 64) . '%';
  }

  // Compute rank via window function before name filter is applied.
  // Suicides excluded from kill counts via build_kill_filters.
  $stmt = $pdo->prepare("
    SELECT *
    FROM (
      SELECT
        attacker_guid               AS guid,
        attacker                    AS name,
        COUNT(*)                    AS frags,
        SUM(damage)                 AS damage,
        RANK() OVER (ORDER BY COUNT(*) DESC) AS rank
      FROM frags
      $kills_base
      GROUP BY attacker_guid, attacker
    ) ranked
    $name_clause
    ORDER BY rank
    LIMIT $limit
  ");
  $stmt->execute(array_merge($kills_params, $name_params));
  $rows = [];
  foreach ($stmt->fetchAll() as $r) {
    $rows[$r['guid']] = array_merge($r, ['deaths' => 0]);
  }

  // Deaths per target — includes suicides
  $stmt = $pdo->prepare("
    SELECT target_guid, COUNT(*) AS deaths
    FROM frags
    $deaths_base
    GROUP BY target_guid
  ");
  $stmt->execute($deaths_params);
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
  [$kills_where, $kills_params]   = build_kill_filters($get);
  [$deaths_where, $deaths_params] = build_filters($get);
  $limit = limit_param($get);

  // Validate: 64-char hex
  if (!preg_match('/^[a-f0-9]{64}$/', $guid)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid guid']);
    return;
  }

  $kills_params[':guid']  = $guid;
  $deaths_params[':guid'] = $guid;

  $attacker_clause = 'WHERE attacker_guid = :guid'
    . ($kills_where  ? (' AND ' . implode(' AND ', $kills_where))  : '');

  $target_clause   = 'WHERE target_guid = :guid'
    . ($deaths_where ? (' AND ' . implode(' AND ', $deaths_where)) : '');

  // Kills by weapon
  $stmt = $pdo->prepare("
    SELECT weapon, COUNT(*) AS frags, SUM(damage) AS damage
    FROM frags $attacker_clause
    GROUP BY weapon ORDER BY frags DESC LIMIT $limit
  ");
  $stmt->execute($kills_params);
  $kills_by_weapon = $stmt->fetchAll();

  // Kills by target
  $stmt = $pdo->prepare("
    SELECT target AS name, target_guid AS guid, COUNT(*) AS frags, SUM(damage) AS damage
    FROM frags $attacker_clause
    GROUP BY target_guid, target ORDER BY frags DESC LIMIT $limit
  ");
  $stmt->execute($kills_params);
  $kills_by_target = $stmt->fetchAll();

  // Deaths by weapon — includes suicides
  $stmt = $pdo->prepare("
    SELECT weapon, COUNT(*) AS deaths
    FROM frags $target_clause
    GROUP BY weapon ORDER BY deaths DESC LIMIT $limit
  ");
  $stmt->execute($deaths_params);
  $deaths_by_weapon = $stmt->fetchAll();

  // Deaths by attacker — includes suicides (self-kills show as own name)
  $stmt = $pdo->prepare("
    SELECT attacker AS name, attacker_guid AS guid, COUNT(*) AS deaths
    FROM frags $target_clause
    GROUP BY attacker_guid, attacker ORDER BY deaths DESC LIMIT $limit
  ");
  $stmt->execute($deaths_params);
  $deaths_by_attacker = $stmt->fetchAll();

  // Kills by level
  $stmt = $pdo->prepare("
    SELECT level, COUNT(*) AS frags, SUM(damage) AS damage
    FROM frags $attacker_clause
    GROUP BY level ORDER BY frags DESC LIMIT $limit
  ");
  $stmt->execute($kills_params);
  $kills_by_level = $stmt->fetchAll();

  // Deaths by level — includes suicides
  $stmt = $pdo->prepare("
    SELECT level, COUNT(*) AS deaths
    FROM frags $target_clause
    GROUP BY level ORDER BY deaths DESC LIMIT $limit
  ");
  $stmt->execute($deaths_params);
  $deaths_by_level = $stmt->fetchAll();

  // Summary totals (kills exclude suicides; deaths include them)
  $stmt = $pdo->prepare("
    SELECT COUNT(*) AS frags, SUM(damage) AS damage
    FROM frags $attacker_clause
  ");
  $stmt->execute($kills_params);
  $totals = $stmt->fetch();

  // Rank: consistent with leaderboard — suicides excluded from kill counts
  [$rank_where, $rank_params] = build_kill_filters($get);
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

  // Total deaths (includes suicides)
  $stmt = $pdo->prepare("
    SELECT COUNT(*) AS deaths
    FROM frags $target_clause
  ");
  $stmt->execute($deaths_params);
  $deaths_total = (int) $stmt->fetchColumn();

  // Nemesis: the player who has killed this player the most (suicides included)
  $nemesis_clause = 'WHERE target_guid = :guid'
    . ($deaths_where ? (' AND ' . implode(' AND ', $deaths_where)) : '');
  $stmt = $pdo->prepare("
    SELECT attacker AS name, attacker_guid AS guid, COUNT(*) AS deaths
    FROM frags $nemesis_clause
    GROUP BY attacker_guid, attacker
    ORDER BY deaths DESC
    LIMIT 1
  ");
  $stmt->execute($deaths_params);
  $nemesis = $stmt->fetch() ?: null;

  echo json_encode([
    'guid'               => $guid,
    'rank'               => $rank,
    'frags'              => (int) $totals['frags'],
    'deaths'             => $deaths_total,
    'damage'             => (int) $totals['damage'],
    'nemesis'            => $nemesis,
    'kills_by_weapon'    => $kills_by_weapon,
    'kills_by_target'    => $kills_by_target,
    'kills_by_level'     => $kills_by_level,
    'deaths_by_weapon'   => $deaths_by_weapon,
    'deaths_by_attacker' => $deaths_by_attacker,
    'deaths_by_level'    => $deaths_by_level,
  ]);
}
