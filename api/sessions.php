<?php
/**
 * POST /api/sessions
 *
 * Accepts one anonymous session record from a Quetoo game client. The client
 * posts twice per session: once at startup with its build and hardware, and
 * once at exit with the session duration and map count. Both bodies carry the
 * same sessionId, and the second updates the row the first inserted.
 *
 * The client identifier is md5(guid + UTC date), computed on the player's
 * machine, so a raw GUID never reaches this service. It is hashed again here
 * with ANALYTICS_SALT before storage - see hash_token() in config.php for why
 * the second hop matters.
 *
 * This route is deliberately unauthenticated. /api/frags and /api/captures
 * accept a request only from an IP the master server currently lists, but a
 * game client is not a listed server, and an open-source client cannot hold a
 * secret. Anyone can read this URL out of the source and post whatever they
 * like. The field bounds below and the per-IP rate limit raise the cost of
 * doing so; they do not prevent it. Treat aggregate counts accordingly.
 *
 * Expected payload, session start:
 * {
 *   "event":       "start",
 *   "token":       "b1946ac92492d2347c6235b4d2611184",
 *   "sessionId":   "3f2504e0-4f89-41d3-9a0c-0305e82c3301",
 *   "version":     "v1.0.108",
 *   "build":       "arm64-apple-darwin",
 *   "buildNumber": "1234",
 *   "platform":    "macOS",
 *   "device":      "Apple M3 Max",
 *   "vendor":      "metal",
 *   "renderer":    "metal",
 *   "cpuCores":    10,
 *   "systemRamMb": 32768
 * }
 *
 * Expected payload, session end:
 * {
 *   "event":     "end",
 *   "token":     "b1946ac92492d2347c6235b4d2611184",
 *   "sessionId": "3f2504e0-4f89-41d3-9a0c-0305e82c3301",
 *   "duration":  4210,
 *   "maps":      3
 * }
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/common.php';

header('Content-Type: application/json');

/**
 * Requests allowed per client address per RATE_WINDOW seconds. A well behaved
 * client posts twice per session, so this leaves room for a player who
 * restarts repeatedly while several players share one address.
 */
const RATE_LIMIT = 60;
const RATE_WINDOW = 3600;

function fail(int $code, string $message): never {
  http_response_code($code);
  echo json_encode(['error' => $message]);
  exit;
}

/**
 * Reads a measurement field, storing NULL for anything outside its plausible
 * range. An implausible number is dropped on its own rather than clamped, so
 * that it cannot be mistaken for a real reading, and the rest of the record
 * survives - a session that idled for a week should not also lose its map
 * count.
 */
function bounded(array $body, string $key, int $min, int $max): ?int {
  if (!isset($body[$key]) || !is_numeric($body[$key])) {
    return null;
  }
  $value = (int) $body[$key];
  return ($value < $min || $value > $max) ? null : $value;
}

/**
 * Reads a string field, cut to the column width. The cut counts characters, as
 * the utf8mb4 columns do; cutting bytes could slice a multi-byte sequence in
 * half, which MySQL rejects in strict mode.
 *
 * This uses PCRE rather than mb_substr, because mbstring is a separate package
 * that a PHP install need not have, and its absence is a fatal error rather
 * than a degraded result. The /u modifier makes `.` match one UTF-8 character.
 *
 * It also fails on input that is not valid UTF-8, and that is stored as NULL.
 * No honest client sends such a field, and passing the bytes through would put
 * a value in the statement that strict mode rejects, losing the whole record.
 */
function truncated(array $body, string $key, int $length): ?string {
  if (!isset($body[$key]) || !is_scalar($body[$key])) {
    return null;
  }
  if (!preg_match('/^.{0,' . $length . '}/us', (string) $body[$key], $match)) {
    return null;
  }
  return $match[0];
}

/**
 * Counts this request against the caller's address. The address is hashed
 * before it is written, and the raw value is never stored.
 */
function rate_limit(PDO $pdo): void {
  $address = $_SERVER['REMOTE_ADDR'] ?? '';
  if ($address === '') {
    return;
  }

  $stmt = $pdo->prepare(
    'INSERT INTO analytics_rate (ip_hash, window_start, requests)
     VALUES (:ip_hash, UTC_TIMESTAMP(), 1)
     ON DUPLICATE KEY UPDATE
       requests = IF(window_start < UTC_TIMESTAMP() - INTERVAL :window SECOND, 1, requests + 1),
       window_start = IF(window_start < UTC_TIMESTAMP() - INTERVAL :window2 SECOND, UTC_TIMESTAMP(), window_start)'
  );
  $stmt->execute([
    ':ip_hash' => hash_token($address),
    ':window'  => RATE_WINDOW,
    ':window2' => RATE_WINDOW,
  ]);

  $count = $pdo->prepare('SELECT requests FROM analytics_rate WHERE ip_hash = ?');
  $count->execute([hash_token($address)]);

  if ((int) $count->fetchColumn() > RATE_LIMIT) {
    fail(429, 'Too Many Requests');
  }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  fail(405, 'Method Not Allowed');
}

$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body) || array_is_list($body)) {
  fail(400, 'Expected a JSON object');
}

// The client spells its fields in camelCase, as the C struct members do.
$body = normalize_event_keys($body);

$event = $body['event'] ?? '';
if (!is_string($event) || ($event !== 'start' && $event !== 'end')) {
  fail(400, 'event must be "start" or "end"');
}

$token = $body['token'] ?? '';
if (!is_string($token) || !preg_match('/^[0-9a-f]{32}$/', $token)) {
  fail(400, 'token must be 32 lowercase hex characters');
}

$session_id = $body['session_id'] ?? '';
if (!is_string($session_id) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $session_id)) {
  fail(400, 'sessionId must be a UUID');
}

$pdo = db_connect();

rate_limit($pdo);

try {
  if ($event === 'start') {
    $stmt = $pdo->prepare(
      'INSERT INTO sessions (session_id, token, date, version, build, build_number, platform, device, vendor, renderer, cpu_cores, system_ram_mb)
       VALUES (:session_id, :token, UTC_DATE(), :version, :build, :build_number, :platform, :device, :vendor, :renderer, :cpu_cores, :system_ram_mb)
       ON DUPLICATE KEY UPDATE
         version = VALUES(version), build = VALUES(build), build_number = VALUES(build_number),
         platform = VALUES(platform), device = VALUES(device), vendor = VALUES(vendor),
         renderer = VALUES(renderer), cpu_cores = VALUES(cpu_cores), system_ram_mb = VALUES(system_ram_mb)'
    );
    $stmt->execute([
      ':session_id'    => $session_id,
      ':token'         => hash_token($token),
      ':version'       => truncated($body, 'version', 64),
      ':build'         => truncated($body, 'build', 64),
      ':build_number'  => truncated($body, 'build_number', 32),
      ':platform'      => truncated($body, 'platform', 32),
      ':device'        => truncated($body, 'device', 128),
      ':vendor'        => truncated($body, 'vendor', 64),
      ':renderer'      => truncated($body, 'renderer', 32),
      ':cpu_cores'     => bounded($body, 'cpu_cores', 0, 1024),
      ':system_ram_mb' => bounded($body, 'system_ram_mb', 0, 4194304),
    ]);
  } else {
    // An end record can arrive without its start, if the start request was
    // lost. Insert the row so the session is still counted, rather than
    // discarding what we were told.
    $stmt = $pdo->prepare(
      'INSERT INTO sessions (session_id, token, date, duration, maps)
       VALUES (:session_id, :token, UTC_DATE(), :duration, :maps)
       ON DUPLICATE KEY UPDATE duration = VALUES(duration), maps = VALUES(maps)'
    );
    $stmt->execute([
      ':session_id' => $session_id,
      ':token'      => hash_token($token),
      ':duration'   => bounded($body, 'duration', 0, 86400),
      ':maps'       => bounded($body, 'maps', 0, 10000),
    ]);
  }
} catch (Exception $e) {
  fail(500, 'Internal error');
}

http_response_code(204);
