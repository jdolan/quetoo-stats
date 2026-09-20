# Quetoo Stats

**Read [`AGENTS.md`](../AGENTS.md) first.** It is the shared instruction set for coding agents, and
it carries the pipeline this repository sits in, the constraints that live outside it, and the rules
that fail silently.

Repeated here because each one fails silently, with no error:

- **Raw player GUIDs are never stored.** `hash_guid()` in `config.php` applies HMAC-SHA256 with
  `STATS_SALT`. Every row, response and filter uses the 64-character digest. Changing the salt
  orphans every row ever written.
- **Suicides count as deaths, not as kills.** A kill or frag query MUST use `build_kill_filters()`,
  which adds `attacker_guid != target_guid`. A death query MUST use `build_filters()`.
- **Rank is computed over the whole dataset, before any name filter**, so a player found by search
  still reports their global position.
- **The server info keys in `api/servers.php` are engine cvar names**, and the engine renames them.
  A key read there SHOULD fall back to its older spelling, because servers upgrade on their own
  schedule.
