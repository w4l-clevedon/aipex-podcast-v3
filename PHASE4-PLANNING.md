# Phase 4 — Listen Analytics (scoped, not built)

Two related but distinct features, deliberately kept separate from Phase 3
since both need new tracking infrastructure rather than just dashboard
display work.

## 1. Episode play counts (historical, low risk)

**What it needs:**
- A `wp_aipex_play_events` table (or a simple `aipex_play_count` postmeta
  counter if we don't need per-play timestamps/analytics, just totals).
  Recommend the table — a counter alone can't answer "plays this month" or
  feed a future "trending episodes" feature, and it's barely more code.
- `podcast.js` fires a `play` event listener on the `<audio>` element (both
  the inline `[aipex_podcast_player]` and the floating player), debounced so
  scrubbing/replaying doesn't inflate counts — e.g. only count once per
  page-load per episode, not once per `play` event fired.
- One AJAX endpoint (`wp_ajax_aipex_track_play` / `nopriv`) that inserts a
  row. No nonce-gated auth needed beyond the standard nonce (this is public
  data, not sensitive), but should be rate-limited per IP/session to deter
  trivial inflation — a transient-based "already counted this episode this
  session" check is enough for a v1.
- Dashboard gets a "Most played" list (top 10, e.g.) and per-episode counts
  could surface on the episode edit screen.

**Open questions before building:**
- Counting plays per session/IP, or literally every play event? (Recommend
  per-session — much harder to game, "good enough" for a content
  dashboard rather than ad-billing-grade analytics.)
- Does this need to feed anything else later (sponsor reporting, "most
  popular show" badges on the front end)? Worth knowing now since it affects
  whether the table needs a `show_id`/`presenter_id` denormalised column for
  faster aggregate queries, vs. joining through the relationship table every
  time.

**Risk: low.** Self-contained, doesn't touch existing code paths, easy to
roll back (drop the table) if it turns out not to be useful.

## 2. Live "listeners online" count (higher risk, lower value for the effort)

**What it needs:**
- A heartbeat: every visitor's browser pings an AJAX endpoint every N
  seconds (e.g. 30s) while a page is open, which writes/refreshes a
  short-lived transient keyed by a session ID.
- Dashboard counts active transients to approximate "online now."
- **This cannot be cached.** If the site ever adds page caching (likely, on
  a multi-purpose theme like this one, once traffic grows) the heartbeat
  AJAX call still has to bypass it, which is an extra thing to remember to
  exclude in cache plugin config.
- Adds a recurring AJAX request from every visitor's browser, all the time,
  whether they're actually listening to a podcast or just browsing — that's
  real, continuous server load for a "nice to have" number, not "currently
  playing X people are listening to this episode" (which would be more
  useful and require knowing playback state, not just page presence).

**Recommendation:** the value-to-effort ratio here is weak compared to play
counts. If the actual goal is "show this is a living, active site," a
"plays this week" trending number (built on top of Phase 4.1's play-count
table) achieves that more honestly and far more cheaply than an online-now
ping. Suggest treating true real-time presence as a "build only if a
specific need shows up" item, not a default part of Phase 4.

## Suggested order if/when this gets built
1. Play-count table + tracking (self-contained, useful immediately)
2. Dashboard "Most played" + per-episode counts
3. Revisit live listener count only if there's a concrete reason for it
