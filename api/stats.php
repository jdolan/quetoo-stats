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
 *   ai      int      0 = human only, exclude human-vs-bot kills (default)
 *                    1 = include all (human-vs-bot and human-vs-human)
 *   server  string   Filter by server hostname (e.g. "giblets.quetoo.org")
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
 * @param string $ai_side           'attacker' or 'target' — which side the ai param
 *                                  conditionally forces to human (only when ai=0).
 *                                  Pass '' to skip the conditional filter entirely.
 * @param string $always_human_side Side that is unconditionally required to be human,
 *                                  regardless of the ai param. Pass '' to skip.
 */
function build_filters(array $get, string $ai_side = 'target', string $prefix = '', string $always_human_side = ''): array {
  $where  = [];
  $params = [];

  if (!empty($get['match_id'])) {
    // Validate UUID v4 format before using as a filter
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $get['match_id'])) {
      $k = $prefix . 'match_id';
      $where[]       = "match_id = :$k";
      $params[":$k"] = $get['match_id'];
    }
  }
  if (!empty($get['weapon'])) {
    $k = $prefix . 'weapon';
    $where[]       = "weapon = :$k";
    $params[":$k"] = substr($get['weapon'], 0, 64);
  }
  if (isset($get['mod']) && $get['mod'] !== '') {
    $k = $prefix . 'mod';
    $where[]       = "`mod` = :$k";
    $params[":$k"] = (int) $get['mod'];
  }
  if (!empty($get['level'])) {
    $k = $prefix . 'level';
    $where[]       = "level = :$k";
    $params[":$k"] = substr($get['level'], 0, 64);
  }
  if (!empty($get['server'])) {
    $k = $prefix . 'server';
    $where[]       = "server_hostname = :$k";
    $params[":$k"] = substr($get['server'], 0, 255);
  }

  // Date range on the frag time column (YYYY-MM-DD → Unix timestamps)
  if (!empty($get['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $get['from'])) {
    $k = $prefix . 'from';
    $where[]       = "`time` >= :$k";
    $params[":$k"] = (int) strtotime($get['from']);
  }
  if (!empty($get['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $get['to'])) {
    $k = $prefix . 'to';
    $where[]       = "`time` <= :$k";
    $params[":$k"] = (int) strtotime($get['to'] . ' 23:59:59');
  }

  // ai=0 (default): conditionally require one side to be human.
  $ai = isset($get['ai']) ? (int) $get['ai'] : 0;
  if ($ai === 0 && $ai_side !== '') {
    $where[] = $ai_side . '_ai = 0';
  }
  // Unconditionally require this side to be human, regardless of the ai param.
  if ($always_human_side !== '') {
    $where[] = $always_human_side . '_ai = 0';
  }

  return [$where, $params];
}

/**
 * Returns WHERE conditions appropriate for kill (attacker-side) queries.
 * Suicides are excluded from kills but still count as deaths.
 */
function build_kill_filters(array $get, string $prefix = ''): array {
  [$where, $params] = build_filters($get, 'attacker', $prefix);
  $where[] = 'attacker_guid != target_guid';
  return [$where, $params];
}

/**
 * Returns a WHERE fragment and params that exclude suppressed player names
 * from the attacker side of a frags query (e.g. attacker != 'newbie').
 *
 * @param string $prefix  PDO parameter prefix to avoid collisions in merged queries.
 */
function build_suppress_filter(string $prefix = ''): array {
  $names = defined('LEADERBOARD_SUPPRESS_NAMES') ? LEADERBOARD_SUPPRESS_NAMES : [];
  if (empty($names)) {
    return [[], []];
  }
  $placeholders = [];
  $params       = [];
  foreach ($names as $i => $name) {
    $key                 = ':' . $prefix . 'suppress_' . $i;
    $placeholders[]      = $key;
    $params[$key]        = $name;
  }
  return [['attacker NOT IN (' . implode(', ', $placeholders) . ')'], $params];
}

function limit_param(array $get): int {
  $limit = isset($get['limit']) ? (int) $get['limit'] : 25;
  return max(1, min(200, $limit));
}

/**
 * Filters for the captures table. Supports level, server, date range, and
 * match_id — but not weapon/mod/ai, which are frag-specific.
 */
function build_capture_filters(array $get, string $prefix = ''): array {
  $where  = [];
  $params = [];

  if (!empty($get['match_id'])) {
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $get['match_id'])) {
      $k = $prefix . 'match_id';
      $where[]       = "match_id = :$k";
      $params[":$k"] = $get['match_id'];
    }
  }
  if (!empty($get['level'])) {
    $k = $prefix . 'level';
    $where[]       = "level = :$k";
    $params[":$k"] = substr($get['level'], 0, 64);
  }
  if (!empty($get['server'])) {
    $k = $prefix . 'server';
    $where[]       = "server_hostname = :$k";
    $params[":$k"] = substr($get['server'], 0, 255);
  }
  if (!empty($get['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $get['from'])) {
    $k = $prefix . 'from';
    $where[]       = "`time` >= :$k";
    $params[":$k"] = (int) strtotime($get['from']);
  }
  if (!empty($get['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $get['to'])) {
    $k = $prefix . 'to';
    $where[]       = "`time` <= :$k";
    $params[":$k"] = (int) strtotime($get['to'] . ' 23:59:59');
  }

  $ai = isset($get['ai']) ? (int) $get['ai'] : 0;
  if ($ai === 0) {
    $where[] = 'player_ai = 0';
  }

  return [$where, $params];
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
  // Kills: attacker is always human; when ai=0 target is also human.
  // Deaths: target is always human; when ai=0 attacker is also human.
  [$kills_where, $kills_params] = build_filters($get, 'target', 'k_', 'attacker');
  $kills_where[] = 'attacker_guid != target_guid';  // Exclude suicides
  [$suppress_where, $suppress_params]   = build_suppress_filter('k_');
  $kills_where  = array_merge($kills_where, $suppress_where);
  $kills_params = array_merge($kills_params, $suppress_params);

  [$deaths_where, $deaths_params] = build_filters($get, 'attacker', 'd_', 'target');
  [$cap_where, $cap_params]       = build_capture_filters($get, 'c_');
  $limit = limit_param($get);

  $kills_base  = $kills_where  ? ('WHERE ' . implode(' AND ', $kills_where))  : '';
  $deaths_base = $deaths_where ? ('WHERE ' . implode(' AND ', $deaths_where)) : '';
  $cap_base    = $cap_where    ? ('WHERE ' . implode(' AND ', $cap_where))    : '';

  // Name filter applied on the outer query so rank reflects global position.
  $name_clause = '';
  $name_params = [];
  if (!empty($get['name'])) {
    $name_clause           = 'WHERE name LIKE :name';
    $name_params[':name']  = '%' . substr($get['name'], 0, 64) . '%';
  }

  // Validate sort/dir — injected literally into SQL so must be whitelisted.
  $valid_sorts = ['frags', 'deaths', 'kd', 'damage', 'captures', 'time_played', 'name'];
  $sort = isset($get['sort']) && in_array($get['sort'], $valid_sorts, true) ? $get['sort'] : 'frags';
  $dir  = isset($get['dir'])  && $get['dir'] === 'asc' ? 'ASC' : 'DESC';

  $order_expr = match($sort) {
    'deaths'     => "deaths $dir",
    'kd'         => "CASE WHEN deaths = 0 THEN frags ELSE frags / deaths END $dir",
    'damage'     => "damage $dir",
    'captures'   => "captures $dir",
    'time_played'=> "time_played $dir",
    'name'       => "name $dir",
    default      => "frags $dir, damage $dir",
  };

  // Single unified query: kills LEFT JOIN deaths LEFT JOIN captures LEFT JOIN matches.
  // RANK() always reflects global frags position regardless of sort order.
  // Tiebreaker is damage DESC so equal-frag players rarely share a rank.
  // Suicides excluded from kills via build_kill_filters; deaths include them.
  $stmt = $pdo->prepare("
    SELECT *
    FROM (
      SELECT
        k.guid,
        k.name,
        k.frags,
        k.damage,
        COALESCE(d.deaths, 0)      AS deaths,
        COALESCE(c.captures, 0)    AS captures,
        COALESCE(t.time_played, 0) AS time_played,
        RANK() OVER (ORDER BY k.frags DESC, k.damage DESC) AS rank
      FROM (
        SELECT attacker_guid AS guid, attacker AS name,
               COUNT(*) AS frags, CAST(SUM(damage) AS UNSIGNED) AS damage
        FROM frags
        $kills_base
        GROUP BY attacker_guid, attacker
      ) k
      LEFT JOIN (
        SELECT target_guid, COUNT(*) AS deaths
        FROM frags
        $deaths_base
        GROUP BY target_guid
      ) d ON d.target_guid = k.guid
      LEFT JOIN (
        SELECT player_guid, COUNT(*) AS captures
        FROM captures
        $cap_base
        GROUP BY player_guid
      ) c ON c.player_guid = k.guid
      LEFT JOIN (
        SELECT player_guid, CAST(SUM(duration) AS UNSIGNED) AS time_played
        FROM matches
        WHERE player_ai = 0
        GROUP BY player_guid
      ) t ON t.player_guid = k.guid
    ) ranked
    $name_clause
    ORDER BY $order_expr
    LIMIT $limit
  ");
  $stmt->execute(array_merge($kills_params, $deaths_params, $cap_params, $name_params));
  echo json_encode($stmt->fetchAll());
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
    SELECT weapon, COUNT(*) AS frags, CAST(SUM(damage) AS UNSIGNED) AS damage
    FROM frags $attacker_clause
    GROUP BY weapon ORDER BY frags DESC LIMIT $limit
  ");
  $stmt->execute($kills_params);
  $kills_by_weapon = $stmt->fetchAll();

  // Kills by target
  $stmt = $pdo->prepare("
    SELECT target AS name, target_guid AS guid, COUNT(*) AS frags, CAST(SUM(damage) AS UNSIGNED) AS damage
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
    SELECT level, COUNT(*) AS frags, CAST(SUM(damage) AS UNSIGNED) AS damage
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

  // Rank: consistent with leaderboard — suicides excluded from kill counts,
  // and suppressed names excluded so rank reflects leaderboard position.
  [$rank_where, $rank_params]           = build_kill_filters($get);
  [$rank_suppress_where, $rank_suppress_params] = build_suppress_filter('r_');
  $rank_where  = array_merge($rank_where, $rank_suppress_where);
  $rank_params = array_merge($rank_params, $rank_suppress_params);
  $rank_base = $rank_where ? ('WHERE ' . implode(' AND ', $rank_where)) : '';
  $rank_stmt = $pdo->prepare("
    SELECT ranked.rank FROM (
      SELECT attacker_guid,
             RANK() OVER (ORDER BY COUNT(*) DESC, SUM(damage) DESC) AS rank
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

  // Aliases — all distinct names this GUID has played under, ordered by frags
  $stmt = $pdo->prepare("
    SELECT attacker AS name, COUNT(*) AS frags, MAX(ts) AS last_seen
    FROM frags
    WHERE attacker_guid = :alias_guid AND attacker_ai = 0
    GROUP BY attacker
    ORDER BY frags DESC
  ");
  $stmt->execute([':alias_guid' => $guid]);
  $aliases = $stmt->fetchAll();

  // Captures — total and by level
  [$cap_where, $cap_params] = build_capture_filters($get);
  $cap_params[':guid'] = $guid;
  $cap_clause = 'WHERE player_guid = :guid'
    . ($cap_where ? (' AND ' . implode(' AND ', $cap_where)) : '');

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM captures $cap_clause");
  $stmt->execute($cap_params);
  $captures_total = (int) $stmt->fetchColumn();

  $stmt = $pdo->prepare("
    SELECT level, COUNT(*) AS captures
    FROM captures $cap_clause
    GROUP BY level ORDER BY captures DESC LIMIT $limit
  ");
  $stmt->execute($cap_params);
  $captures_by_level = $stmt->fetchAll();

  echo json_encode([
    'guid'               => $guid,
    'rank'               => $rank,
    'frags'              => (int) $totals['frags'],
    'deaths'             => $deaths_total,
    'damage'             => (int) $totals['damage'],
    'captures'           => $captures_total,
    'nemesis'            => $nemesis,
    'aliases'            => $aliases,
    'kills_by_weapon'    => $kills_by_weapon,
    'kills_by_target'    => $kills_by_target,
    'kills_by_level'     => $kills_by_level,
    'deaths_by_weapon'   => $deaths_by_weapon,
    'deaths_by_attacker' => $deaths_by_attacker,
    'deaths_by_level'    => $deaths_by_level,
    'captures_by_level'  => $captures_by_level,
  ]);
}
