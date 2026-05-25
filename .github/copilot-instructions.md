# Quetoo Stats — Copilot Instructions

## Ecosystem Overview

This repo is one piece of a three-part pipeline:

```
../quetoo  (C game engine)
  └─ cl_main.c      — client sets `guid` cvar (uuid, userinfo)
  └─ g_combat.c     — G_Kill() logs g_frag_t structs per kill
  └─ g_item.c       — G_PickupFlag() logs g_capture_t structs per CTF capture
  └─ sv_game.c      — Sv_FragLog() / Sv_CaptureLog() POST JSON to sv_stats_url / sv_captures_url
        │
        ▼
quetoo-stats  (this repo — PHP/MariaDB REST API)
  └─ POST /api/frags    — ingests frag batches
  └─ POST /api/captures — ingests CTF capture batches
  └─ GET  /api/stats    — leaderboard + player deep-stats (frags + captures)
  └─ GET  /api/options  — distinct servers, maps, and CTF teams for filter UI
        │
        ▼
../quetoo-www  (Hugo CMS website)
  └─ static/js/stats.js — fetches API, renders /stats page
```

### Quetoo game side (../quetoo)

- **Client GUID** (`src/client/cl_main.c`): the `guid` cvar (`CVAR_USER_INFO | CVAR_ARCHIVE`) is auto-initialized with a random UUID if empty, then sent to the server in the userinfo connect packet. Bots get their own GUIDs via `g_ai_info.c`.
- **Frag capture** (`src/game/default/g_combat.c`): `G_Kill()` builds a `g_frag_t` with fields `level`, `attacker`, `attacker_guid`, `attacker_ai`, `target`, `target_guid`, `target_ai`, `weapon`, `mod`, `damage`, `time`. AI-vs-AI frags are dropped; frags with empty GUIDs are skipped.
- **CTF capture** (`src/game/default/g_item.c`): `G_PickupFlag()` builds a `g_capture_t` with fields `level`, `player`, `player_guid`, `player_ai`, `team`, `time`. Only logged when the player GUID is non-empty.
- **HTTP POST** (`src/server/sv_game.c`): `Sv_FragLog()` / `Sv_CaptureLog()` serialize arrays as JSON and call `Net_HttpPostAsync`. Configured via `sv_stats_url` (default `.../api/frags`) and `sv_captures_url` (default `.../api/captures`); both are disabled when their URL is empty or `sv_public <= 0`.

### Website frontend (../quetoo-www)

- Hugo site; the `/stats` page is `content/stats/_index.md` rendered by `layouts/stats/list.html`.
- All API logic lives in `static/js/stats.js`. API base URLs are hardcoded: `https://giblets.quetoo.org/api/stats` and `https://giblets.quetoo.org/api/options`.
- The leaderboard table is rendered by `renderLeaderboard()`; clicking a row navigates to `#<guid>` and loads player detail via `showPlayer(guid)`. Navigation is hash-based.
- `colorize()` / `stripColors()` handle Quetoo in-game color codes in player names.

---

## Stack

- **Apache** + **mod_php** (PHP 8+), **MariaDB** (utf8mb4)
- URL routing via `.htaccess` rewrite rules — no framework
- Two database tables: `frags` and `captures` in the `quetoo_stats` database (see `schema.sql`)

## Setup & Deployment

```bash
sudo bash install.sh        # full LAMP setup, MariaDB config, Let's Encrypt cert
sudo php maintenance/backfill_hostnames.php   # one-time CLI migration script
```

There are no automated tests or build steps.

## Configuration

- `config.php` is the committed template; copy to `config.local.php` for real credentials
- `config.local.php` is `.gitignored` and never committed
- `config.php` is always loaded first; `config.local.php` overrides via `require_once` at the bottom of `config.php`
- `STATS_SALT` and `SERVER_HOSTNAMES` are defined in `config.local.php`

## Architecture

### Request Flow

`.htaccess` rewrites `/api/<name>` → `api/<name>.php` and `/api/stats/<guid>` → `api/stats.php?guid=<guid>`. Each endpoint file is self-contained: it requires `config.php`, sets headers, validates the request, and echoes JSON.

### Endpoint Files

| File | Method | Route |
|---|---|---|
| `api/frags.php` | POST | `/api/frags` |
| `api/captures.php` | POST | `/api/captures` |
| `api/stats.php` | GET | `/api/stats` and `/api/stats/<guid>` |
| `api/options.php` | GET | `/api/options` |
| `api/servers.php` | — | shared helper (not a route) |

### Server Authentication

`POST /api/frags` and `POST /api/captures` only accept requests from IPs registered with the Quetoo master server (UDP `giblets.quetoo.org:1996`). The master server list is cached in `/tmp/quetoo_servers.json` (60 s TTL); per-server info strings in `/tmp/quetoo_server_info.json` (300 s TTL).

### GUID Privacy

Raw player GUIDs are **never stored**. `hash_guid()` in `config.php` applies HMAC-SHA256 with `STATS_SALT` before insertion. All API responses and filter parameters use the hashed form (64-char hex).

### match_id

Each `POST /api/frags` or `POST /api/captures` generates a UUID v4 and assigns it as `match_id` to every row in that batch. This groups all events from a single match submission and can be used as a filter in `GET /api/stats`.

## Key Conventions

- **Suicides** (`attacker_guid = target_guid`) are excluded from kill/frag counts but are counted as deaths. Use `build_kill_filters()` for kill queries and `build_filters()` for death queries.
- **AI bots** are tracked via `attacker_ai` / `target_ai` in frags and `player_ai` in captures (all TINYINT 0/1). The default filter (`ai=0`) hides bot attackers/capturers from leaderboards.
- **Rank** is computed via `RANK() OVER (ORDER BY COUNT(*) DESC)` as a window function on the full dataset before any name filter is applied, so player rank reflects global position.
- **Captures** are enriched onto leaderboard rows (`captures: N`, default 0 for non-CTF players) and added to player deep-stats (`captures`, `captures_by_level`). `build_capture_filters()` in `stats.php` applies level/server/date/ai filters to the captures table (weapon/mod filters do not apply to captures).
- **String inputs** are truncated to column length (`substr($val, 0, 64)`) before binding — no ORM, raw PDO with named placeholders throughout.
- **Server hostname resolution** priority: `SERVER_HOSTNAMES` config map → live UDP info query → IP fallback.
- All responses are `Content-Type: application/json`; HTTP status codes are set explicitly before `exit`.
