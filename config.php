<?php
/**
 * Database configuration.
 * Copy this file to config.local.php and fill in credentials.
 * config.local.php is .gitignored and never committed.
 *
 * config.local.php is loaded FIRST, before any defaults below are applied,
 * so its overrides actually take effect. (define() is first-write-wins in
 * PHP, so defining a constant here before requiring config.local.php would
 * silently and permanently lock in the default instead of the override.)
 */

$db_config = [
  'host'   => '127.0.0.1',
  'port'   => 3306,
  'dbname' => 'quetoo_stats',
  'user'   => 'quetoo',
  'pass'   => '',  // set in config.local.php
];

if (file_exists(__DIR__ . '/config.local.php')) {
  require_once __DIR__ . '/config.local.php';
}

/**
 * Secret salt for HMAC-SHA256 GUID hashing. MUST be set in
 * config.local.php - there is intentionally no working default here.
 *
 * Raw player GUIDs are never stored anywhere (see hash_guid() below) -
 * only their HMAC-SHA256 digest, computed with this salt. If the
 * effective salt ever changes, hash_guid() output changes for every
 * player simultaneously, and every existing row in
 * frags/captures/matches becomes permanently unmatchable to any future
 * hash, with no raw GUIDs left to recompute against. This is a
 * whole-database, unrecoverable event - never rotate this value casually.
 */
if (!defined('STATS_SALT')) {
  fwrite(STDERR, "FATAL: STATS_SALT must be defined in config.local.php\n");
  http_response_code(500);
  exit(1);
}

/**
 * Player names suppressed from the leaderboard.
 * Frags and captures are always stored; suppression is query-time only.
 * Override in config.local.php if needed.
 */
if (!defined('LEADERBOARD_SUPPRESS_NAMES')) {
  define('LEADERBOARD_SUPPRESS_NAMES', ['newbie']);
}

/**
 * Map of server IP -> display hostname.
 * Define in config.local.php, e.g.:
 *   define('SERVER_HOSTNAMES', ['1.2.3.4' => 'myserver.example.com']);
 */

/**
 * Generate a cryptographically-random UUID v4.
 */
function uuid4(): string {
  $b = random_bytes(16);
  $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
  $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
  return sprintf('%s-%s-%s-%s-%s',
    bin2hex(substr($b, 0, 4)),
    bin2hex(substr($b, 4, 2)),
    bin2hex(substr($b, 6, 2)),
    bin2hex(substr($b, 8, 2)),
    bin2hex(substr($b, 10, 6))
  );
}

function db_connect(): PDO {
  global $db_config;
  $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $db_config['host'], $db_config['port'], $db_config['dbname']);
  return new PDO($dsn, $db_config['user'], $db_config['pass'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
}

/**
 * Hash a raw GUID with the server-side salt so raw GUIDs are never stored.
 * This is a one-way, permanent mapping - there is no stored raw GUID to
 * fall back on if the salt ever changes. See the STATS_SALT comment above.
 */
function hash_guid(string $guid): string {
  return hash_hmac('sha256', $guid, STATS_SALT);
}

/**
 * Fills in a default date window of the current calendar month when `from`
 * and/or `to` are absent in a query parameter array. This mirrors the
 * website's default "This Month" selection. Callers that want a different
 * window should pass explicit values.
 */
function apply_default_date_window(array $get): array {
  if (empty($get['from'])) {
    $get['from'] = date('Y-m-01');
  }
  if (empty($get['to'])) {
    $get['to'] = date('Y-m-d');
  }
  return $get;
}
