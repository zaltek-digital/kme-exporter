=== KME Exporter ===
Contributors: zaltek
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress plugin that exports KME content for manual import into SPS2.

== Description ==

The KME (Key Molecules Expenditure) WordPress microsite is being retired and its content
moved into SPS2. This plugin is the KME side of that move, and works exactly like the NDO
exporter (`zaltek-digital/ndo-exporter`). It **packages everything on the KME WordPress site
into a single bundle (a zip)** that you download from wp-admin. The bundle is then imported
into SPS2 by hand, using the `kme-import` module in `zaltek-digital/sps2` (the KME tab of
SPS → Import there), which rebuilds the pages in SPS2's `kme` post type.

The charts themselves stay in the KME Laravel app; only the WordPress layer moves.

    KME WordPress                     person                  SPS2
    Tools → Export      ──download──▶  bundle .zip  ──upload──▶  SPS → Import, KME tab
    (or wp kme-export build)                                  → preview → import

**It isn't an API and it doesn't sync.** Nothing calls into the KME site, and nothing runs on a
schedule. A bundle is a snapshot of the site at the moment you download it. Export and import
again whenever SPS2 needs to catch up; SPS2 only updates what changed.

Rules this plugin keeps:

* **Read-only.** It never changes content, settings or users.
* **Complete.** Every page, post and attachment is exported, except trashed items, along
  with their revisions. Deciding what to leave out, rename or restructure is SPS2's job, not
  the exporter's.
* **No secrets in the bundle.** No passwords, keys or plugin secrets are exported.
* **Nothing left behind.** The zip is built in a temp file and deleted once it's been
  downloaded.
* **Admins only.** Downloading needs `manage_options` and a valid nonce.

== What it exports ==

For every page, post and attachment that isn't trashed:

* identity and place in the tree: source ID, type, status, slug, title, parent, menu order, URL path
* content: page template, raw block content, excerpt, and a content hash for change detection
* dates and author (login and email)
* post meta (non-protected keys) and terms
* **ACF fields** by field name: the pages' `blocks` (text and collections of pinned reports),
  `summary`, `change_history`, and the reports' `date_extracted` and `parent`
* computed lists: every embed (`create-block/kme-embed` chart URLs, `nhs/iframe`, `core/embed`,
  raw `<iframe>`), internal links, block usage, the preset texts each report uses, and KME's
  rendered HTML for each block. SPS2 needs the rendered HTML for blocks that are built on the server.
* **full revision history** for each item, plus the **"Ready for Live" archived versions**
  that chain to each report

It also exports the site's menus, the **preset texts** (`options.json`), the authors, and
media files. Pending Ready for Live copies are exported but marked with the post they replace;
SPS2 doesn't import them, so publish them before the final export.

== Installation ==

The same way as our other plugins: build a zip, then upload it in wp-admin.

1. Bump `Version:` in `kme-exporter.php`.
2. Run `./zip-plugin.sh`. This writes `versions/kme-exporter-vX.Y.Z.zip`.
3. On the KME site, go to Plugins → Add New → Upload Plugin, choose the zip and activate it.
   On a server with WP-CLI you can instead run `wp plugin install <zip> --activate --force`.

Updating is the same three steps. There's no GitHub Actions, tag or release process.
There's nothing to configure.

== Usage ==

Go to Tools → Export and choose **KME**, at the top of the list. A table then shows what the
bundle will contain (items by type and status, revisions, archived versions, media files,
chart embeds and preset texts), with the plugin and bundle format versions. Only
administrators see the option.

Click **Download Export File** to get `kme-export-<site>-<YYYYmmdd-HHMMSS>.zip`. Then import it
in SPS2 on the KME tab of SPS → Import, which shows a preview before anything is written.

For a large site, or a server with tight PHP time or memory limits, use WP-CLI instead:
`wp kme-export build --out=<dir>`.

== The bundle ==

Inside the zip:

* `manifest.json`: `schema: "kme-export"`, `schema_version`, generator (plugin version),
  `generated_at`, site info, counts, and the **change index**:
  `{source_id, type, status, parent, modified_gmt, content_sha256}` for every item. SPS2 uses
  it to update only what changed on a re-import.
* `items/{id}.json`: one per item, including its `acf`, `revisions[]` and `archives[]`
* `media.json` + `files/{id}/{basename}`
* `menus.json`, `users.json` (authors only: login, email, display name)
* `options.json`: the preset texts

**Contract.** `docs/contract.md` and `schema/*.json` define the bundle format. They are the
source of truth that SPS2's importer is built against. `schema_version` is separate from the
plugin version:

* a **minor** bump only adds optional fields
* a **major** bump is breaking, and SPS2 refuses any major version it doesn't support.

To change the contract, update the schema, fixture and version here first. Then update SPS2.
`fixtures/bundle-v1/` is a sample bundle, and SPS2's tests use a copy of it.

== WP-CLI ==

* `wp kme-export build --out=<dir>`: write the bundle zip to `<dir>`. Options:
  * `--dir`: write an unzipped folder instead.
  * `--anonymise-authors`: replace author logins and emails with placeholders. Use it for
    anything committed to a repo.
  * `--include=<ids>`: only these items, plus their attachments.
  * `--max-revisions=<n>`: keep each item's newest n revisions.

  The contract fixture (`fixtures/bundle-v1/`) is built with these; see `fixtures/README.md`.
* `wp kme-export manifest`: print the manifest, including the change index.
* `wp kme-export item <id>`: print one item.

== Development ==

    composer install
    composer lint       # PHPCS (WordPress-Extra + WordPress-Docs)
    composer analyse    # PHPStan level 6
    composer test       # PHPUnit against the WordPress test library

The code follows SPS2's conventions:

* strict types
* an `ABSPATH` guard in every file
* the `kme_export_` function prefix
* tabs for indentation.

To develop locally, work against a local copy of the KME site in a Herd site (e.g.
`kme.test`, `D:\repos\kme`). On that copy:

* keep `define( 'KME_ENV', 'local' );` in `wp-config.php`. Never use `production` locally:
  that value turns on the redirects to the live SPS login.
* deactivate `wp-remote-users-sync`, because it syncs users with the live SPS site
* activate `Key-Molecules-Plugin`, so preset blocks render into the bundle's `rendered` HTML
* symlink or install this plugin into `wp-content/plugins/`.

The copy contains real user accounts, so keep it local.

== Status ==

In development. Design decisions are recorded in the KME → SPS2 build spec. Rollout order:
local → UAT → prod.

== Changelog ==

= 0.1.0 =
* First version, matching ndo-exporter 0.2.0: the "KME" option on Tools → Export with a table
  of what it will contain, and `wp kme-export build`. Adds ACF fields, Ready for Live archived
  versions and the preset texts to the bundle.
