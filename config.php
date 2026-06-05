<?php
/**
 * Database configuration.
 * Copy this file to config.local.php and fill in credentials.
 * config.local.php is .gitignored and never committed.
 */

$db_config = [
  'host'   => '127.0.0.1',
  'port'   => 3306,
  'dbname' => 'quetoo_stats',
  'user'   => 'quetoo',
  'pass'   => '',  // set in config.local.php
];

/**
 * Secret salt for HMAC-SHA256 GUID hashing.
 * Override in config.local.php with a random secret.
 */
define('STATS_SALT', 'change-me-in-config-local');

/**
 * Player names suppressed from the leaderboard.
 * Frags and captures are always stored; suppression is query-time only.
 * Override in config.local.php if needed.
 */
define('LEADERBOARD_SUPPRESS_NAMES', ['newbie']);

/**
 * Map of server IP -> display hostname.
 * Define in config.local.php, e.g.:
 *   define('SERVER_HOSTNAMES', ['1.2.3.4' => 'myserver.example.com']);
 */

if (file_exists(__DIR__ . '/config.local.php')) {
  require_once __DIR__ . '/config.local.php';
}

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
 */
function hash_guid(string $guid): string {
  return hash_hmac('sha256', $guid, STATS_SALT);
}
