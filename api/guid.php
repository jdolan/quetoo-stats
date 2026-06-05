<?php
/**
 * GET /api/guid?guid=<raw-guid>
 *
 * Converts a raw player GUID (UUID) to its HMAC-SHA256 hashed form as stored
 * in the stats database.  Clients use this to identify their own rows in the
 * leaderboard and to construct deep-links to their player stats page.
 *
 * Query parameters:
 *   guid  string  Raw player GUID (UUID format, e.g. from the "guid" cvar)
 *
 * Response (200):
 *   { "guid": "<64-char hex string>" }
 *
 * Response (400):
 *   { "error": "missing or invalid guid parameter" }
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$raw = $_GET['guid'] ?? '';

if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $raw)) {
  http_response_code(400);
  echo json_encode(['error' => 'missing or invalid guid parameter']);
  exit;
}

echo json_encode(['guid' => hash_guid($raw)]);
