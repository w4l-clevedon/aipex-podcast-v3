# Aipex Podcast System — v3.0.0

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
