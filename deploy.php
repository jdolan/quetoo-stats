<?php
/**
 * POST /deploy
 *
 * GitHub webhook endpoint. Validates the HMAC-SHA256 signature using the
 * shared secret defined in config.local.php as DEPLOY_SECRET, then runs
 * git fetch + reset --hard to deploy the latest main branch.
 *
 * Set up in GitHub: Settings → Webhooks → Add webhook
 *   Payload URL:  https://giblets.quetoo.org/deploy
 *   Content type: application/json
 *   Secret:       <value of DEPLOY_SECRET in config.local.php>
 *   Events:       Just the push event
 */

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}

if (!defined('DEPLOY_SECRET') || !DEPLOY_SECRET) {
  http_response_code(500);
  error_log('quetoo-stats deploy: DEPLOY_SECRET not configured');
  exit;
}

$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$body      = file_get_contents('php://input');
$expected  = 'sha256=' . hash_hmac('sha256', $body, DEPLOY_SECRET);

if (!hash_equals($expected, $signature)) {
  http_response_code(403);
  exit;
}

$payload = json_decode($body, true);

// Only deploy pushes to main
if (($payload['ref'] ?? '') !== 'refs/heads/main') {
  http_response_code(200);
  echo json_encode(['status' => 'skipped', 'reason' => 'not main branch']);
  exit;
}

$repo = '/var/www/quetoo-stats';
$cmd  = "git -C " . escapeshellarg($repo) . " fetch origin 2>&1"
      . " && git -C " . escapeshellarg($repo) . " reset --hard origin/main 2>&1";

$output = shell_exec($cmd);

error_log("quetoo-stats deploy: " . trim($output));

header('Content-Type: application/json');
echo json_encode(['status' => 'ok', 'output' => $output]);
