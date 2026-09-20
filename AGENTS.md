# Quetoo Stats

A PHP and MariaDB REST API that ingests frag and capture events from Quetoo dedicated servers, and
serves the leaderboard the website renders. Apache with mod_php, no framework, no build step and no
automated tests.

This file is the shared instruction set for coding agents. `.github/copilot-instructions.md` points
here. Read this first.

It deliberately records only what a careful reading of the code does **not** reveal: rules that fail
silently, constraints that live outside this repository, and decisions that look like bugs. For the
routes, the schema and the query shapes, read `.htaccess`, `schema.sql`, `migrate/` and `api/`. They
are never out of date. `README.md` carries the operator-facing setup and the `merge_guid.php`
runbook.

## Sibling repositories

All of these are jdolan's, checked out beside `quetoo-stats/`. **You are free to change them.** When
a fix belongs in one of them, make it there rather than working around it here, and say so.

| Repository | What it is | Reach for it when |
|---|---|---|
| `../quetoo` | The game engine. `src/server/sv_game.c` posts the batches this API ingests, and `src/client/cl_main.c` owns the `guid` cvar that identifies a player | An ingested field changes shape, or you need to know what the server actually sends |
| `../quetoo-www` | The Hugo website. `static/js/stats.js` renders `/stats`, and `static/js/servers.js` renders the server browser | A response field changes, or the pages that consume this API need to change with it |

The engine, this API and the website form one pipeline. A change to what the engine posts, or to
what this API returns, is not finished until the other end changes with it.

## Rules that fail silently

These produce no error. They are the reason this file exists.

### Raw GUIDs are never stored

`hash_guid()` in `config.php` applies HMAC-SHA256 with `STATS_SALT`. Every row, every response and
every filter parameter uses the 64-character hex digest. A code path that writes or returns a raw
client UUID is a privacy defect, not a formatting choice.

`STATS_SALT` has no default on purpose, and the process exits if it is undefined. **Changing it
orphans every row ever written**, because there is no way to recover a raw GUID and rehash it. That
is why `maintenance/merge_guid.php` exists, and why an admin has to identify an orphaned player by
name.

### Suicides count as deaths, not as kills

`attacker_guid = target_guid` rows are real and are stored. A kill or frag query MUST use
`build_kill_filters()`, which adds `attacker_guid != target_guid`. A death query MUST use
`build_filters()`, which does not. Reaching for the wrong one silently changes what the leaderboard
means.

### Rank is global, and is computed before the name filter

`ROW_NUMBER() OVER (ORDER BY COUNT(*) DESC, attacker_guid ASC)` runs over the whole dataset. A
player who is found by a name search still reports their global position. Moving the window function
after the filter would renumber the result set, which reads as a plausible simplification and is
wrong.

### Server info keys are the engine's to rename

`api/servers.php` reads `sv_hostname`, `sv_map`, `g_gameplay` and `sv_maxClients` out of the status
string. These are engine cvar names, not an agreed schema. Quetoo v1.0.106 renamed
`sv_max_clients` to `sv_maxClients`, and the browser rendered every server as `N/0` until this repo
followed.

Servers upgrade on their own schedule, so a key read here SHOULD fall back to the older spelling.

## Constraints that live outside the code

- **Ingest is authenticated by the master server, not by a secret.** `POST /api/frags` and
  `POST /api/captures` accept a request only from an IP the Quetoo master at UDP
  `giblets.quetoo.org:1996` currently lists. There is no API key. A server that the master does not
  know cannot report, and a master outage stops ingest.
- **The master list and the per-server info strings are cached in `/tmp`.** See `CACHE_TTL` and
  `INFO_CACHE_TTL` in `api/common.php`. A test that expects an immediate effect from a server
  joining or leaving MUST account for the TTL, or delete the cache file.
- **Hostname resolution has a fixed priority**, in `server_hostname()`: the `SERVER_HOSTNAMES`
  config map, then the `X-Quetoo-Hostname` header the server reports, then a live UDP status query,
  then the raw IP. Only newer engines send the header, and the UDP fallback is keyed by IP alone, so
  it cannot separate two instances behind one address.
- **`config.local.php` holds every credential and is never committed.** `config.php` is the
  committed template, and it requires the local file near the top, before its own defaults apply, so
  that a local definition wins. `STATS_SALT`, `SERVER_HOSTNAMES` and `LEADERBOARD_SUPPRESS_NAMES`
  are defined there.
- **`match_id` is minted per request, not per match.** Each POST generates a UUID v4 and stamps it
  on every row in that batch. Two batches from one game are two `match_id` values.

## Conventions

- Every string bound into a query is truncated to its column width first, with `substr($val, 0, 64)`
  or `0, 255`. There is no ORM. Everything is raw PDO with named placeholders.
- Every response is `Content-Type: application/json`, and the HTTP status is set explicitly before
  `exit`.
- Each endpoint file is self-contained: it requires `config.php`, validates the request and echoes
  JSON. `api/common.php` and `api/servers.php` are shared helpers, not routes.
