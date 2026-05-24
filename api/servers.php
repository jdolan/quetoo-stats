<?php
/**
 * Master server query helpers.
 *
 * Queries the local Quetoo master server (UDP port 1996) for the list of
 * registered public dedicated servers, and exposes is_registered_server()
 * and server_hostname() for use by API endpoints.
 *
 * Server lists and info strings are cached in /tmp to avoid hitting the
 * master and game servers on every inbound request.
 */

define('MASTER_HOST',       'giblets.quetoo.org');
define('MASTER_PORT',       1996);
define('MASTER_QUERY',      "\xFF\xFF\xFF\xFFgetservers");
define('MASTER_REPLY',      "\xFF\xFF\xFF\xFFservers ");
define('CACHE_FILE',        '/tmp/quetoo_servers.json');
define('INFO_CACHE_FILE',   '/tmp/quetoo_server_info.json');
define('CACHE_TTL',         60);   // seconds — master list
define('INFO_CACHE_TTL',    300);  // seconds — per-server info strings

/**
 * @brief Query the master server and return an array of ['ip', 'port'] pairs.
 */
function query_master_servers(): array {
  $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
  if ($sock === false) {
    error_log('quetoo-stats: socket_create failed: ' . socket_strerror(socket_last_error()));
    return [];
  }

  socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);

  if (@socket_sendto($sock, MASTER_QUERY, strlen(MASTER_QUERY), 0, MASTER_HOST, MASTER_PORT) === false) {
    error_log('quetoo-stats: socket_sendto failed: ' . socket_strerror(socket_last_error($sock)));
    socket_close($sock);
    return [];
  }

  $response = '';
  @socket_recvfrom($sock, $response, 65535, 0, $addr, $port);
  socket_close($sock);

  $header     = MASTER_REPLY;
  $header_len = strlen($header);

  if (strlen($response) < $header_len || strncmp($response, $header, $header_len) !== 0) {
    error_log('quetoo-stats: unexpected master server response');
    return [];
  }

  $servers = [];
  $offset  = $header_len;

  while ($offset + 6 <= strlen($response)) {
    // 4 bytes IPv4 in network (big-endian) byte order, then 2 bytes port
    $parts     = unpack('C4ip/nport', substr($response, $offset, 6));
    $servers[] = [
      'ip'   => "{$parts['ip1']}.{$parts['ip2']}.{$parts['ip3']}.{$parts['ip4']}",
      'port' => (int) $parts['port'],
    ];
    $offset += 6;
  }

  return $servers;
}

/**
 * @brief Return the cached server list, refreshing from the master if stale.
 *        Returns an array of ['ip', 'port'] pairs.
 */
function get_registered_servers(): array {
  if (file_exists(CACHE_FILE) && (time() - filemtime(CACHE_FILE)) < CACHE_TTL) {
    $cached = json_decode(file_get_contents(CACHE_FILE), true);
    if (is_array($cached)) {
      return $cached;
    }
  }

  $servers = query_master_servers();
  file_put_contents(CACHE_FILE, json_encode($servers), LOCK_EX);
  return $servers;
}

/**
 * @brief Returns true if $ip is a registered public dedicated server.
 */
function is_registered_server(string $ip): bool {
  foreach (get_registered_servers() as $s) {
    if ($s['ip'] === $ip) {
      return true;
    }
  }
  return false;
}

/**
 * @brief Query a single game server for its info string.
 *        Sends the Quetoo "info" out-of-band packet and parses the response.
 *        Returns the sv_hostname string, or null on failure.
 */
function query_server_info(string $ip, int $port): ?string {
  $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
  if ($sock === false) {
    return null;
  }

  socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);

  $query = "\xFF\xFF\xFF\xFFinfo 2026";
  if (@socket_sendto($sock, $query, strlen($query), 0, $ip, $port) === false) {
    socket_close($sock);
    return null;
  }

  $response = '';
  @socket_recvfrom($sock, $response, 1024, 0, $addr, $rport);
  socket_close($sock);

  // Expected: \xFF\xFF\xFF\xFFinfo\n<hostname>\<level>\<game>\<clients>\<max>
  $prefix = "\xFF\xFF\xFF\xFFinfo\n";
  if (strncmp($response, $prefix, strlen($prefix)) !== 0) {
    return null;
  }

  $payload  = substr($response, strlen($prefix));
  $parts    = explode('\\', $payload);
  $hostname = trim($parts[0]);
  return $hostname !== '' ? $hostname : null;
}

/**
 * @brief Return the cached map of IP -> sv_hostname, refreshing if stale.
 */
function get_server_info_map(): array {
  if (file_exists(INFO_CACHE_FILE) && (time() - filemtime(INFO_CACHE_FILE)) < INFO_CACHE_TTL) {
    $cached = json_decode(file_get_contents(INFO_CACHE_FILE), true);
    if (is_array($cached)) {
      return $cached;
    }
  }

  $map = [];
  foreach (get_registered_servers() as $s) {
    $hostname = query_server_info($s['ip'], $s['port']);
    if ($hostname !== null) {
      $map[$s['ip']] = $hostname;
    }
  }

  file_put_contents(INFO_CACHE_FILE, json_encode($map), LOCK_EX);
  return $map;
}

/**
 * @brief Returns the display hostname for a server IP.
 * Priority: SERVER_HOSTNAMES config override > live info query > IP fallback.
 */
function server_hostname(string $ip): string {
  $overrides = defined('SERVER_HOSTNAMES') ? SERVER_HOSTNAMES : [];
  if (isset($overrides[$ip])) {
    return $overrides[$ip];
  }

  $map = get_server_info_map();
  return $map[$ip] ?? $ip;
}
