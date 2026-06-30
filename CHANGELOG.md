# Aipex Podcast System — v3.3.0

## v3.3.0 — Phase 3: admin cleanup + real dashboard

- **Removed** the V1 → V2 Migration tool (`class-migration.php` and its
  admin screen) — the v1→v2 migration this plugin's history refers to is
  long done; this was dead weight in the admin menu.
- **Removed** "Replace Bad Audio URLs With Dropbox Links" from Tools &
  Scanners — same reasoning, a one-time recovery tool from an earlier
  incident that's no longer relevant.
- **Dashboard now shows real numbers**: published counts for Podcasts,
  Shows, Hosts/Presenters, Guests, and Sponsors, replacing the "Modular
  podcast CMS is active" placeholder.
- **Shortcodes admin page reorganised** — grouped by category (Episode,
  Episode grids, Show/Series, Presenter, Guest, Sponsor) with a one-line
  description per shortcode, instead of one long undifferentiated list. A
  "Recommended" section at the top leads with `aipex_relationship_grid`.
  Also flagged explicitly: `aipex_latest_podcasts` is always unfiltered by
  design — placing it on a show/presenter page (instead of
  `aipex_show_podcasts`/`aipex_presenter_podcasts`) will show every
  published episode site-wide rather than that page's own. This was the
  root cause of the "series page doesn't show its own podcasts" report —
  not a relationship-table bug, a content/widget-choice issue, but worth
  making harder to fall into by accident.
- **Play counts and live listener tracking scoped but not built** — see
  `PHASE4-PLANNING.md` for the architecture, open questions, and a
  recommendation to deprioritise "listeners online" relative to play
  counts given the effort/value gap (continuous heartbeat traffic and
  cache-bypass requirements for comparatively low value vs. a simple
  play-count table).

## v3.2.0 — Phase 2: Entity API + generic Relationship Grid

- **Fixed**: presenter contact email/phone were captured by ACF but never
  rendered anywhere on the front end (`link_icons()` in `class-shortcodes.php`
  was missing them from its field map).
- **New `Aipex_Podcast_Entity` class** (`class-entity.php`) — a thin,
  type-aware wrapper: `Aipex_Podcast_Entity::load($id)` then
  `->title() / ->url() / ->image() / ->episodes() / ->shows() / ->hosts() /
  ->guests() / ->sponsors() / ->social_links() / ->contact()`, regardless of
  whether the loaded entity is a host, guest, sponsor or show. Built on top
  of `Aipex_Podcast_Relationships` and `Aipex_Podcast_Fields` — adds no new
  data access, just a consistent shape for calling code to work with.
- **New `[aipex_relationship_grid]` shortcode**, with `relationship`
  (episodes/shows/hosts/guests/sponsors), `entity_id` (optional override) and
  `limit` attributes, plus AJAX load-more. This is the one widget that
  replaces the "one shortcode per pairing" pattern — and specifically covers
  pairings that never had a dedicated shortcode at all (a host's shows, a
  sponsor's guests, etc.), since those are now just another `relationship`
  value on the same widget rather than new code.
- **New Elementor widget**: "Relationship Grid", with its own Relationship /
  Entity ID / Limit controls (the existing shortcode-wrapper widget class
  only exposes a Limit control, so this one is a dedicated class).
- **Existing shortcodes unchanged** — `aipex_presenter_podcasts`,
  `aipex_series_podcasts` etc. still work exactly as before. They were
  deliberately left as-is rather than rewritten as thin wrappers in this
  pass, since they already read from the relationship table via Phase 1 and
  rewriting working code carries real risk for no functional gain on a live
  site. Worth revisiting once the generic widget has been used in practice.

**Not in this release** (Phase 3): Elementor widget consolidation beyond
the one new widget above, and the admin screen redesign (per-entity
Overview/Relationships/Episodes/Shows/Import/Diagnostics/AI/History layout).

## v3.1.0 — Phase 1 of the relationship architecture

Implements `Aipex_Podcast_Relationships`, a real join table (`wp_aipex_relationships`)
backing every entity-to-entity lookup in the plugin, replacing the
meta_query/LIKE-against-serialized-ACF-data approach.

- **New table**: `from_type, from_id, to_type, to_id` edges. Direct edges
  (episode→show, episode→host, episode→guest, episode→sponsor, show→sponsor)
  are written from existing ACF relationship fields. There's no direct
  host↔show, host↔guest, host↔sponsor, guest↔show, or guest↔sponsor field in
  the current data model — those are computed as *derived* two-hop lookups
  through the episode at read time (e.g. a host's shows = the shows of the
  episodes they appear on), not stored separately.
- **`episodes_for() / shows_for() / hosts_for() / guests_for() / sponsors_for()`**
  — the only place anything in the plugin should query relationship data
  going forward.
- **Kept in sync automatically** two ways, since not every write path goes
  through the same code: `acf/save_post` (admin edit screen) and inside
  `Aipex_Podcast_Fields::update()` (migration tool, sponsor admin actions,
  any future programmatic writes). Both call the same idempotent
  `sync_post()`.
- **`Aipex_Podcast_Fields::query_episodes()`** now filters via the
  relationship table (`post__in`) instead of `meta_query` `LIKE` scans, and
  gained `guest_id`/`sponsor_id` filters alongside the existing
  `series_id`/`presenter_id` — laying groundwork for Phase 2's generic
  Relationship Grid widget.
- One-time backfill (`migrate_all()`) runs automatically on activation and
  on the standard version-gated `admin_init` upgrade check, so the live
  site's existing ACF data populates the new table without manual steps.
  A "Rebuild Relationship Index" button was also added to Tools & Scanners
  for manual re-runs.

**Not in this release** (Phase 2/3 per the agreed plan): the generic
Relationship Grid shortcode/widget, the Entity API, and the Elementor widget
consolidation. Existing shortcodes (`aipex_presenter_podcasts` etc.) are
unchanged on the outside — they now read from the new table internally, but
nothing placed on existing pages needs to change.

## v3.0.1

- **Renamed the presenter rewrite slug from `presenter` to `radio-presenter`.**
  On womensradiostation.com, WP Job Manager already owns a rewrite rule for
  the generic word "presenter" and is registered ahead of this plugin's rule,
  so it always won the match first and produced a 404 even though the post
  type registration itself was correct. A single-word generic slug was always
  a collision risk on any site with a handful of other plugins active — that
  matters more once this plugin runs on sites other than this one.
- Replaced the rewrite-rule-based legacy redirect with a 404-path-matching
  redirect (`maybe_redirect_legacy_presenter_url` in `class-core.php`). This
  catches both the old `/host/slug/` and `/presenter/slug/` URLs and sends
  them to the current `/radio-presenter/slug/` permalink with a 301,
  regardless of which other plugin's rewrite rule "claims" the path first —
  it only needs WordPress to have already given up and reached its 404
  template, which is exactly what was happening before this fix.
- If `/radio-presenter/` ever collides with something else on a future
  install, the slug is defined in one place (`class-post-types.php`) and easy
  to change again — this is exactly the kind of per-site conflict a future
  Settings-screen "URL slug" field should let installers configure without
  touching code.

## v3.0.0

Consolidated rebuild of `aipex-podcast-system-v2`, fixing a set of structural
bugs found during code review plus the first steps toward making this plugin
installable on sites other than womensradiostation.com.

## Fixed

- **Presenter pages 404'd.** `class-context-fixes.php` registered the
  `aipex_presenter` post type a second time with a different rewrite slug
  (`/host/`) and `query_var`, competing with the original registration in
  `class-post-types.php`. Whichever registration last flushed the rewrite
  rules determined what URLs actually worked, so presenter permalinks were
  unreliable. There is now exactly one registration, using the `/presenter/`
  slug. A 301 redirect from the old `/host/` slug is included in case any
  links were ever shared or indexed while it was live.

- **Presenters weren't showing their linked shows/episodes.** Three separate
  implementations of episode/relationship matching existed
  (`Aipex_Podcast_Utils`, `Aipex_Podcast_Fields`, and a third copy inside
  `class-context-fixes.php`, which also overrode the shortcodes and AJAX
  handlers at runtime via `remove_shortcode`/`remove_action`). Depending on
  which one was bound for a given request, relationship lookups could simply
  miss data that was actually stored. `class-fields.php` is now the single
  field-access layer; `class-utils.php` and `class-context-fixes.php` have
  been deleted.

- **`eval()`-based Elementor widgets removed.** `class-elementor.php`
  previously generated ~24 PHP classes at runtime via `eval()`. Replaced with
  one concrete `Aipex_Podcast_Elementor_Shortcode_Widget` class configured
  per-instance through its constructor.

- **Dropbox secrets encrypted at rest.** App secret, manual token, and OAuth
  access/refresh tokens were stored as plaintext `wp_options`. They're now
  encrypted with AES-256-GCM via `class-crypto.php` before being saved.

## Productisation (first pass)

- Hardcoded sponsor ID (`20740`) and brand colour (`#e4005a`) pulled into a
  new **Settings** screen (`class-settings.php`) under the Podcasts admin
  menu. `podcast.css` now reads the brand colour from a CSS custom property
  (`--aipex-brand`) instead of the hex value being hardcoded throughout the
  stylesheet.
- Still TODO before this is resale-ready for other agencies/sites: per-site
  ACF field mapping/onboarding, a license-key/update-server mechanism, and
  removing the remaining site-specific assumptions (e.g. the `womensradio`
  deploy path lives only in the *old* repo's GitHub Actions workflow, which
  was intentionally not copied here).

## Not carried over from v2

- `class-utils.php`, `class-context-fixes.php` — deleted, see above.
- The old repo's GitHub Actions deploy workflows, `DEPLOYMENT.md`, and the
  leftover `.zip` patch file — this repo holds only the plugin folder per
  request. CI/CD and deployment process should be set up fresh for this repo
  rather than copying the old SSH-push-on-every-commit-to-main pattern.
