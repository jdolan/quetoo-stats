<?php
/**
 * CLI-only tool: merge frags/captures/matches history from one GUID onto
 * another.
 *
 * Used when a player loses their quetoo.cfg (and thus their `guid` cvar),
 * gets a new client-generated UUID, and their prior stats are orphaned
 * under the old (now unused) GUID hash. The admin identifies the old,
 * orphaned hash with --find=<name>, a case-insensitive substring search
 * over player names (there is no way to recover the GUID from the raw
 * UUID, since raw GUIDs are never stored — see hash_guid() in config.php),
 * then merges it onto the player's current GUID with --old/--new.
 *
 * Deliberately NOT exposed over HTTP: this only runs via direct shell
 * access (SSH), which is a stronger boundary than any bearer token or IP
 * allowlist we could add to a web endpoint, and it avoids adding any new
 * attack surface for an operation that is rare, destructive, and always
 * performed by a trusted admin on someone else's behalf.
 *
 * Usage:
 *   php maintenance/merge_guid.php --find=<substring>
 *   php maintenance/merge_guid.php --old=<guid-or-hash> --new=<guid-or-hash> [--confirm] [--note="..."]
 *
 * --find performs a case-insensitive substring search (SQL LIKE %term%)
 * over attacker/target/player names across frags, captures, and matches.
 * Player names may contain Quake color escapes (e.g. "^1j^9dolan"), so
 * search on a plain substring like "dolan" rather than the exact decorated
 * name. Results are grouped by GUID hash with sample names, per-table row
 * counts, and last-seen timestamp, to help identify the right --old value.
 *
 * --old and --new each accept either:
 *   - a raw client UUID, e.g. as pasted from a backed-up quetoo.cfg, OR
 *   - an already-hashed 64-char hex value, e.g. copied directly out of the
 *     attacker_guid/target_guid/player_guid columns, or from --find output,
 *     or from GET /api/guid?guid=<raw>.
 *
 * Without --confirm this is a dry run: it reports how many rows in each
 * table match --old, plus a sample of the associated player names, so the
 * admin can sanity-check that this is really the same person before
 * anything is written. With --confirm, all matching rows are updated in a
 * single transaction and the merge is recorded in guid_merges for audit
 * purposes.
 */

require_once __DIR__ . '/../config.php';

// table => [guid column => name column] pairs to search / merge across.
const GUID_TABLES = [
  'frags'    => ['attacker_guid' => 'attacker', 'target_guid' => 'target'],
  'captures' => ['player_guid' => 'player'],
  'matches'  => ['player_guid' => 'player'],
];

function usage(): never {
  fwrite(STDERR, "Usage: php merge_guid.php --find=<substring>\n");
  fwrite(STDERR, "       php merge_guid.php --old=<guid-or-hash> --new=<guid-or-hash> [--confirm] [--note=\"reason\"]\n");
  exit(1);
}

/**
 * @brief Case-insensitive substring search for $term across all name
 *        columns in GUID_TABLES. Returns rows of
 *        [guid, names (comma-joined sample), rows, last_seen], grouped by
 *        GUID hash and ordered by most recently seen first.
 */
function find_guids(PDO $pdo, string $term): array {
  // Each UNION branch gets its own positional `?` for the LIKE term, since
  // PDO doesn't reliably support reusing one named placeholder across all
  // branches of a UNION.
  $selects = [];
  foreach (GUID_TABLES as $table => $cols) {
    foreach ($cols as $guid_col => $name_col) {
      $selects[] = "SELECT `$guid_col` AS guid, `$name_col` AS name, ts FROM `$table` WHERE `$name_col` LIKE ?";
    }
  }
  $union = implode(' UNION ALL ', $selects);

  $sql = "
    SELECT guid,
           GROUP_CONCAT(DISTINCT name ORDER BY ts DESC SEPARATOR ', ') AS names,
           COUNT(*) AS rows_matched,
           MAX(ts) AS last_seen
    FROM ($union) combined
    GROUP BY guid
    ORDER BY last_seen DESC
  ";

  $params = array_fill(0, count($selects), '%' . $term . '%');

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @brief Accepts either a raw UUID or an already-hashed 64-char hex value
 *        and returns the hashed form, so callers never have to care which
 *        one an admin happened to have on hand.
 */
function normalize_guid(string $input): string {
  $input = trim($input);

  if (preg_match('/^[a-f0-9]{64}$/i', $input)) {
    return strtolower($input);
  }

  if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $input)) {
    return hash_guid($input);
  }

  fwrite(STDERR, "error: '$input' is not a valid raw UUID or 64-char hex hash\n");
  exit(1);
}

/**
 * @brief Returns a small sample of distinct player names associated with
 *        $guid in $table, most recent first, for admin confirmation.
 */
function sample_names(PDO $pdo, string $table, string $guid_col, string $name_col, string $guid): array {
  $stmt = $pdo->prepare(
    "SELECT DISTINCT `$name_col` FROM `$table` WHERE `$guid_col` = :guid ORDER BY ts DESC LIMIT 5"
  );
  $stmt->execute([':guid' => $guid]);
  return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$opts = getopt('', ['find:', 'old:', 'new:', 'confirm', 'note::']);

if (isset($opts['find'])) {
  $term = trim((string) $opts['find']);
  if ($term === '') {
    usage();
  }

  $pdo  = db_connect();
  $rows = find_guids($pdo, $term);

  if (empty($rows)) {
    echo "No names matching '$term' found.\n";
    exit(0);
  }

  printf("%-64s  %6s  %-19s  %s\n", 'guid', 'rows', 'last seen', 'names');
  foreach ($rows as $row) {
    printf("%-64s  %6d  %-19s  %s\n", $row['guid'], $row['rows_matched'], $row['last_seen'], $row['names']);
  }
  echo "\nUse a guid hash above as --old or --new to merge_guid.php.\n";
  exit(0);
}

if (!isset($opts['old'], $opts['new'])) {
  usage();
}

$old     = normalize_guid($opts['old']);
$new     = normalize_guid($opts['new']);
$confirm = isset($opts['confirm']);
$note    = isset($opts['note']) ? substr((string) $opts['note'], 0, 255) : null;

if ($old === $new) {
  fwrite(STDERR, "error: --old and --new resolve to the same GUID hash; nothing to do\n");
  exit(1);
}

$pdo = db_connect();

echo "Old GUID hash: $old\n";
echo "New GUID hash: $new\n\n";

$counts = [];
foreach (GUID_TABLES as $table => $cols) {
  foreach ($cols as $col => $name_col) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$col` = :guid");
    $stmt->execute([':guid' => $old]);
    $n = (int) $stmt->fetchColumn();
    $counts["$table.$col"] = $n;

    $names_desc = '';
    if ($n > 0) {
      $names = sample_names($pdo, $table, $col, $name_col, $old);
      $names_desc = '  (names: ' . implode(', ', $names) . ')';
    }
    printf("  %-24s %6d rows%s\n", "$table.$col", $n, $names_desc);
  }
}

$total = array_sum($counts);
if ($total === 0) {
  echo "\nNo rows found for --old GUID. Nothing to merge.\n";
  exit(0);
}

if (!$confirm) {
  echo "\nDry run only — $total row(s) would be updated. Re-run with --confirm to apply.\n";
  exit(0);
}

$pdo->beginTransaction();
try {
  $updated = [];
  foreach (GUID_TABLES as $table => $cols) {
    foreach ($cols as $col => $name_col) {
      $stmt = $pdo->prepare("UPDATE `$table` SET `$col` = :new WHERE `$col` = :old");
      $stmt->execute([':new' => $new, ':old' => $old]);
      $updated["$table.$col"] = $stmt->rowCount();
    }
  }

  $audit = $pdo->prepare(
    'INSERT INTO guid_merges (old_guid, new_guid, rows_updated, note) VALUES (:old, :new, :rows, :note)'
  );
  $audit->execute([
    ':old'  => $old,
    ':new'  => $new,
    ':rows' => array_sum($updated),
    ':note' => $note,
  ]);

  $pdo->commit();
} catch (Exception $e) {
  $pdo->rollBack();
  fwrite(STDERR, 'error: merge failed, rolled back: ' . $e->getMessage() . "\n");
  exit(1);
}

echo "\nMerged:\n";
foreach ($updated as $key => $n) {
  printf("  %-24s %6d rows\n", $key, $n);
}
echo "\nDone. Recorded in guid_merges.\n";
