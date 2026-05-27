<?php
/**
 * GET /api/servers
 *
 * Returns live status for every public Quetoo server registered with the
 * master.  The server list is cached; per-server status is fetched fresh on
 * every request.
 *
 * All status queries are fired in a single UDP burst then collected via
 * socket_select() for up to 500 ms — no threads needed.
 *
 * Response:
 * [
 *   {
 *     "ip":          "1.2.3.4",
 *     "port":        1998,
 *     "hostname":    "Quetoo.org Official - US East",
 *     "map":         "dm_quetoo",
 *     "num_clients": 3,
 *     "max_clients": 16,
 *     "players": [
 *       { "name": "jdolan", "score": 42, "ping": 18 },
 *       ...
 *     ]
 *   },
 *   ...
 * ]
 */

require_once __DIR__ . '/common.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  http_response_code(405);
  echo json_encode(['error' => 'Method Not Allowed']);
  exit;
}

/**
 * @brief Parse a raw status response UDP payload into a structured array.
 *
 * Quetoo responds to "status" with:
 *   \xFF\xFF\xFF\xFFprint\n<infostring>\n<player lines>
 * where <infostring> is \key\value\... and each player line is:
 *   score ping "name"
 */
function parse_status_response(string $response, string $fallback_ip): ?array {
  $prefix = "\xFF\xFF\xFF\xFFprint\n";
  if (strncmp($response, $prefix, strlen($prefix)) !== 0) {
    return null;
  }

  $body  = substr($response, strlen($prefix));
  $lines = explode("\n", $body);

  $infostring = trim($lines[0] ?? '');
  $parts      = explode('\\', $infostring);
  if (isset($parts[0]) && $parts[0] === '') {
    array_shift($parts); // strip leading '\' if present
  }
  $info = [];
  for ($i = 0; $i + 1 < count($parts); $i += 2) {
    $info[$parts[$i]] = $parts[$i + 1];
  }

  $players = [];
  for ($i = 1; $i < count($lines); $i++) {
    $line = trim($lines[$i]);
    if ($line === '') {
      continue;
    }
    if (preg_match('/^(-?\d+)\s+(\d+)\s+"?(.*?)"?\s*$/', $line, $m)) {
      $players[] = [
        'name'  => $m[3],
        'score' => (int)$m[1],
        'ping'  => (int)$m[2],
      ];
    }
  }

  $hostname    = $info['sv_hostname']    ?? ($info['hostname']      ?? $fallback_ip);
  $map         = $info['map_name']       ?? ($info['mapname']       ?? ($info['level'] ?? ''));
  $gameplay    = $info['g_gameplay']     ?? '';
  $num_clients = count($players);
  $max_clients = (int)($info['sv_max_clients'] ?? ($info['sv_maxclients'] ?? ($info['maxclients'] ?? 0)));

  return [
    'hostname'    => $hostname,
    'map'         => $map,
    'gameplay'    => $gameplay,
    'num_clients' => $num_clients,
    'max_clients' => $max_clients,
    'players'     => $players,
  ];
}

/**
 * @brief Fire "status" queries to every server in a single UDP burst, then
 *        collect responses via socket_select() for up to $timeout_ms ms.
 */
function query_all_server_statuses(array $servers, int $timeout_ms = 500): array {
  if (empty($servers)) {
    return [];
  }

  $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
  if ($sock === false) {
    error_log('quetoo-stats: socket_create failed for status burst');
    return [];
  }

  socket_set_nonblock($sock);

  $query = "\xFF\xFF\xFF\xFFstatus 2026";
  foreach ($servers as $s) {
    @socket_sendto($sock, $query, strlen($query), 0, $s['ip'], $s['port']);
  }

  $expected = [];
  foreach ($servers as $s) {
    $expected["{$s['ip']}:{$s['port']}"] = true;
  }

  $results  = [];
  $deadline = microtime(true) + ($timeout_ms / 1000.0);

  while (microtime(true) < $deadline) {
    $remaining_us = (int)(($deadline - microtime(true)) * 1_000_000);
    if ($remaining_us <= 0) {
      break;
    }

    $read = [$sock];
    $w = $e = null;
    $n = @socket_select($read, $w, $e, intdiv($remaining_us, 1_000_000), $remaining_us % 1_000_000);
    if ($n < 1) {
      break;
    }

    $buf = '';
    $from_ip = '';
    $from_port = 0;
    if (@socket_recvfrom($sock, $buf, 4096, 0, $from_ip, $from_port) < 1) {
      continue;
    }

    $key = "{$from_ip}:{$from_port}";
    if (!isset($expected[$key])) {
      continue;
    }

    $status = parse_status_response($buf, $from_ip);
    if ($status !== null) {
      $results[$key] = $status;
    }
  }

  socket_close($sock);
  return $results;
}

// ─────────────────────────────────────────────────────────────────────────────

// Validate sort params.  Whitelist keys to prevent injection.
$sortable = ['hostname', 'map', 'gameplay', 'num_clients', 'max_clients'];
$sort     = in_array($_GET['sort'] ?? '', $sortable, true) ? $_GET['sort'] : 'num_clients';
$dir      = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

$registered = get_registered_servers();
$statuses   = query_all_server_statuses($registered);

$result = [];
foreach ($registered as $s) {
  $key = "{$s['ip']}:{$s['port']}";
  if (!isset($statuses[$key])) {
    continue;
  }
  $result[] = array_merge(['ip' => $s['ip'], 'port' => $s['port']], $statuses[$key]);
}

usort($result, function ($a, $b) use ($sort, $dir) {
  $av = $a[$sort] ?? '';
  $bv = $b[$sort] ?? '';
  $cmp = is_int($av) ? ($av <=> $bv) : strcasecmp((string)$av, (string)$bv);
  return $dir === 'asc' ? $cmp : -$cmp;
});

echo json_encode($result);
