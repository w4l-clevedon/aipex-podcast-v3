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

## 2. Geographic location of listeners shown on a map



## Suggested order if/when this gets built
1. Play-count table + tracking (self-contained, useful immediately)
2. Dashboard "Most played" + per-episode counts
3. Revisit live listener count only if there's a concrete reason for it
