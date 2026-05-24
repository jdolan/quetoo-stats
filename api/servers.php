<?php
/**
 * Master server query helpers.
 *
 * Queries the local Quetoo master server (UDP port 1996) for the list of
 * registered public dedicated servers, and exposes is_registered_server()
 * for use by API endpoints.
 *
 * The server list is cached in /tmp for CACHE_TTL seconds to avoid hitting
 * the master on every inbound request.
 */

define('MASTER_HOST',  'giblets.quetoo.org');
define('MASTER_PORT',  1996);
define('MASTER_QUERY', "\xFF\xFF\xFF\xFFgetservers");
define('MASTER_REPLY', "\xFF\xFF\xFF\xFFservers ");
define('CACHE_FILE',   '/tmp/quetoo_servers.json');
define('CACHE_TTL',    60);  // seconds

/**
 * @brief Query the master server and return an array of IPv4 address strings.
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

  $header = MASTER_REPLY;
  $header_len = strlen($header);

  if (strlen($response) < $header_len || strncmp($response, $header, $header_len) !== 0) {
    error_log('quetoo-stats: unexpected master server response');
    return [];
  }

  $servers = [];
  $offset  = $header_len;

  while ($offset + 6 <= strlen($response)) {
    // 4 bytes IPv4 in network (big-endian) byte order, then 2 bytes port
    $parts  = unpack('C4ip/nport', substr($response, $offset, 6));
    $servers[] = "{$parts['ip1']}.{$parts['ip2']}.{$parts['ip3']}.{$parts['ip4']}";
    $offset += 6;
  }

  return $servers;
}

/**
 * @brief Return the cached server list, refreshing from the master if stale.
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
  return in_array($ip, get_registered_servers(), true);
}
