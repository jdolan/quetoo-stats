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

if (file_exists(__DIR__ . '/config.local.php')) {
  require_once __DIR__ . '/config.local.php';
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
