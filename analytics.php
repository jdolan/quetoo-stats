<?php
/**
 * GET /analytics
 *
 * Password-protected dashboard over the sessions table. Read only, no
 * parameters, no external assets. Protected by HTTP basic auth in .htaccess,
 * against an AuthUserFile outside the document root.
 *
 * A note on what "players" means here, because it is easy to report wrongly.
 * The client token is md5(guid + UTC date), so it changes for every player
 * every day. COUNT(DISTINCT token) is therefore only meaningful within one
 * day. Summed over a range it counts player-days, not people: one player who
 * plays all week appears as seven distinct tokens. Every figure below that
 * spans more than a day is labelled accordingly, and retention is simply not
 * derivable - that was the deliberate cost of the privacy design.
 */

require_once __DIR__ . '/config.php';

$pdo = db_connect();

/**
 * Runs a query and returns all rows.
 */
function rows(PDO $pdo, string $sql, array $args = []): array {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($args);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Formats a duration in seconds as the largest sensible unit.
 */
function duration(?float $seconds): string {
  if ($seconds === null) {
    return '-';
  }
  $seconds = (int) round($seconds);
  if ($seconds < 60) {
    return $seconds . 's';
  }
  if ($seconds < 3600) {
    return intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's';
  }
  return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm';
}

function h(?string $value): string {
  return htmlspecialchars($value ?? '-', ENT_QUOTES, 'UTF-8');
}

$today = rows($pdo, "SELECT COUNT(DISTINCT token) AS players, COUNT(*) AS sessions
                       FROM sessions WHERE date = UTC_DATE()")[0];

$totals = rows($pdo, "SELECT COUNT(*) AS sessions, COUNT(DISTINCT date) AS days,
                             MIN(date) AS first_day
                        FROM sessions")[0];

$last30 = rows($pdo, "SELECT COUNT(*) AS sessions, SUM(players) AS player_days,
                             AVG(players) AS avg_players
                        FROM (SELECT date, COUNT(DISTINCT token) AS players, COUNT(*) AS n
                                FROM sessions WHERE date > UTC_DATE() - INTERVAL 30 DAY
                               GROUP BY date) d")[0];

$daily = rows($pdo, "SELECT date,
                            COUNT(DISTINCT token) AS players,
                            COUNT(*) AS sessions,
                            AVG(duration) AS avg_duration,
                            MAX(duration) AS max_duration,
                            AVG(maps) AS avg_maps
                       FROM sessions
                      WHERE date > UTC_DATE() - INTERVAL 30 DAY
                   GROUP BY date
                   ORDER BY date DESC");

$median = rows($pdo, "SELECT PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY duration) OVER () AS p50
                        FROM sessions WHERE duration IS NOT NULL
                          AND date > UTC_DATE() - INTERVAL 30 DAY LIMIT 1");

/**
 * Breakdowns share a shape: one column, counted over the last 30 days.
 */
function breakdown(PDO $pdo, string $column): array {
  return rows($pdo, "SELECT COALESCE(`$column`, 'unknown') AS value, COUNT(*) AS sessions
                       FROM sessions
                      WHERE date > UTC_DATE() - INTERVAL 30 DAY
                   GROUP BY value ORDER BY sessions DESC, value LIMIT 25");
}

$breakdowns = [
  'Version'   => breakdown($pdo, 'version'),
  'Platform'  => breakdown($pdo, 'platform'),
  'GPU'       => breakdown($pdo, 'device'),
  'Backend'   => breakdown($pdo, 'renderer'),
  'Build'     => breakdown($pdo, 'build'),
];

// CPU cores and memory are ordinal, so they read as a scale rather than a
// league table. Order them by the value, not by the count.
$breakdowns['CPU cores'] = rows($pdo, "SELECT COALESCE(CAST(cpu_cores AS CHAR), 'unknown') AS value,
                                              COUNT(*) AS sessions
                                         FROM sessions
                                        WHERE date > UTC_DATE() - INTERVAL 30 DAY
                                     GROUP BY value ORDER BY cpu_cores IS NULL, cpu_cores");

$breakdowns['Memory'] = rows($pdo, "SELECT CASE
                                            WHEN system_ram_mb IS NULL THEN 'unknown'
                                            WHEN system_ram_mb <  8192 THEN 'under 8 GB'
                                            WHEN system_ram_mb < 16384 THEN '8 to 16 GB'
                                            WHEN system_ram_mb < 32768 THEN '16 to 32 GB'
                                            ELSE '32 GB or more'
                                          END AS value,
                                          COUNT(*) AS sessions,
                                          MIN(COALESCE(system_ram_mb, 0)) AS sort_key
                                     FROM sessions
                                    WHERE date > UTC_DATE() - INTERVAL 30 DAY
                                 GROUP BY value
                                 ORDER BY system_ram_mb IS NULL, sort_key");

$peak = 0;
foreach ($daily as $d) {
  $peak = max($peak, (int) $d['players']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Quetoo analytics</title>
<style>
  :root { color-scheme: dark; --bg:#16181c; --fg:#d8dae0; --dim:#8b8f99; --line:#2a2e35; --bar:#4b7f52; }
  body { background:var(--bg); color:var(--fg); font:14px/1.5 ui-monospace, Menlo, Consolas, monospace;
         margin:0; padding:24px; }
  main { max-width:1000px; margin:0 auto; }
  h1 { font-size:18px; margin:0 0 4px; }
  h2 { font-size:14px; margin:32px 0 8px; color:var(--dim); text-transform:uppercase; letter-spacing:.08em; }
  p.note { color:var(--dim); margin:0 0 24px; max-width:70ch; }
  table { border-collapse:collapse; width:100%; margin-bottom:8px; }
  th, td { text-align:left; padding:4px 10px 4px 0; border-bottom:1px solid var(--line); white-space:nowrap; }
  th { color:var(--dim); font-weight:normal; }
  td.n, th.n { text-align:right; }
  .cards { display:flex; flex-wrap:wrap; gap:24px; margin-bottom:8px; }
  .card { border:1px solid var(--line); padding:12px 16px; min-width:120px; }
  .card b { display:block; font-size:22px; font-weight:normal; }
  .card span { color:var(--dim); font-size:12px; }
  .bar { display:inline-block; height:9px; background:var(--bar); vertical-align:middle; }
  .cols { display:flex; flex-wrap:wrap; gap:0 48px; }
  .cols section { flex:1 1 300px; min-width:300px; }
  footer { color:var(--dim); margin-top:40px; font-size:12px; }
</style>
</head>
<body>
<main>

<h1>Quetoo analytics</h1>
<p class="note">
  The client token changes for every player every day, so a player count is only
  meaningful within a single day. Over a range these are <em>player-days</em>, not
  people. Retention and returning-player figures are not derivable from this data,
  by design.
</p>

<div class="cards">
  <div class="card"><b><?= (int) $today['players'] ?></b><span>players today</span></div>
  <div class="card"><b><?= (int) $today['sessions'] ?></b><span>sessions today</span></div>
  <div class="card"><b><?= number_format((float) $last30['avg_players'], 1) ?></b><span>avg players/day, 30d</span></div>
  <div class="card"><b><?= (int) $last30['player_days'] ?></b><span>player-days, 30d</span></div>
  <div class="card"><b><?= duration($median[0]['p50'] ?? null) ?></b><span>median session, 30d</span></div>
  <div class="card"><b><?= (int) $totals['sessions'] ?></b><span>sessions all time</span></div>
</div>

<h2>Daily, last 30 days</h2>
<table>
  <tr><th>Date</th><th class="n">Players</th><th class="n">Sessions</th>
      <th class="n">Avg session</th><th class="n">Longest</th><th class="n">Avg maps</th><th></th></tr>
<?php foreach ($daily as $d): ?>
  <tr>
    <td><?= h($d['date']) ?></td>
    <td class="n"><?= (int) $d['players'] ?></td>
    <td class="n"><?= (int) $d['sessions'] ?></td>
    <td class="n"><?= duration($d['avg_duration'] === null ? null : (float) $d['avg_duration']) ?></td>
    <td class="n"><?= duration($d['max_duration'] === null ? null : (float) $d['max_duration']) ?></td>
    <td class="n"><?= $d['avg_maps'] === null ? '-' : number_format((float) $d['avg_maps'], 1) ?></td>
    <td><span class="bar" style="width:<?= $peak ? round(140 * $d['players'] / $peak) : 0 ?>px"></span></td>
  </tr>
<?php endforeach; ?>
<?php if (!$daily): ?>
  <tr><td colspan="7">No sessions recorded yet.</td></tr>
<?php endif; ?>
</table>

<div class="cols">
<?php foreach ($breakdowns as $title => $table):
        $max = 0;
        foreach ($table as $r) { $max = max($max, (int) $r['sessions']); } ?>
  <section>
    <h2><?= h($title) ?>, last 30 days</h2>
    <table>
<?php foreach ($table as $r): ?>
      <tr>
        <td><?= h((string) $r['value']) ?></td>
        <td class="n"><?= (int) $r['sessions'] ?></td>
        <td><span class="bar" style="width:<?= $max ? round(100 * $r['sessions'] / $max) : 0 ?>px"></span></td>
      </tr>
<?php endforeach; ?>
<?php if (!$table): ?>
      <tr><td>No data yet.</td></tr>
<?php endif; ?>
    </table>
  </section>
<?php endforeach; ?>
</div>

<footer>
  Counting since <?= h($totals['first_day']) ?> over <?= (int) $totals['days'] ?> days with data.
  Generated <?= h(gmdate('Y-m-d H:i')) ?> UTC.
</footer>

</main>
</body>
</html>
