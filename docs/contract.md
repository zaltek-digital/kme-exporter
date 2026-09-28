# KME export bundle: contract

This document and the JSON Schemas in `../schema/` define the bundle that `kme-exporter`
produces and SPS2's `kme-import` module consumes. **They are the source of truth.** If the
code and this document disagree, one of them has a bug.

- **Identifier:** `schema: "kme-export"`
- **Version:** `schema_version: "1.0"`, which is separate from the plugin version.
  - A **minor** bump (`1.1`) only adds optional fields. Importers must ignore fields they
    don't know, and must leave a target value alone when an optional field is absent.
  - A **major** bump (`2.0`) is breaking. SPS2 refuses any major version it doesn't support.
- **To change the contract:** update the schema, this document, the fixture and the version
  here first. Then upload the new plugin zip to KME, then change SPS2.

## Bundle layout

A zip (or, for fixtures, an unzipped directory) containing:

| Path | Schema | Contents |
|---|---|---|
| `manifest.json` | `manifest.schema.json` | Contract version, generator, site info, counts, and the change index |
| `items/{id}.json` | `item.schema.json` | One file per exported page, post and attachment. `{id}` is the KME post ID. |
| `media.json` | `media.schema.json` | One entry per attachment |
| `files/{id}/{basename}` | — | The attachment files themselves |
| `menus.json` | `menus.schema.json` | Menus, their items, and theme locations |
| `users.json` | `users.schema.json` | Authors of exported items, revisions and archived versions: login, email, display name |
| `options.json` | `options.schema.json` | The shared preset texts (KME's `preset_text_list` ACF option) |

All JSON is UTF-8, pretty-printed, with unescaped slashes and Unicode. Maps (objects) are
always encoded as JSON objects, even when empty or when their keys are numeric.

## What is exported

- **Pages and posts** with status `publish`, `draft`, `pending`, `private` or `future`.
  **Trash and auto-drafts are not exported.**
- **Attachments** with status `inherit`, with their files.
- **Revisions** of each page and post, oldest first. **Autosaves are not exported.**
- **Archived versions** ("Ready for Live" archives, status `archive`) that chain to an
  exported post, oldest first. Archived posts are not items in their own right.
- **Preset texts** (the `preset_text_list` ACF option).
- **Menus** and their theme locations.
- **Authors** of the above. With `--anonymise-authors`, they're replaced by
  `author-{id}` / `author-{id}@example.invalid` placeholders.

**Nothing is filtered by the exporter.** Deciding what to leave out, rename or restructure
is the importer's job, done in SPS2's import map.

**Never exported:**
- passwords, roles, capabilities or any other user data beyond the three author fields
- plugin settings or secrets (apart from the preset texts, which are content)
- the server's filesystem paths
- trashed content
- editor bookkeeping meta (`_edit_lock`, `_edit_last`, `_wp_old_slug`, …)

## Items (`items/{id}.json`)

| Field | Type | Notes |
|---|---|---|
| `source_id` | int | KME post ID. **SPS2 never reuses it as its own ID.** It's the key for matching on re-import. |
| `type` | `page` \| `post` \| `attachment` | |
| `status` | string | |
| `slug`, `title`, `excerpt` | string | As stored |
| `parent` | int \| null | `source_id` of the parent |
| `menu_order` | int | |
| `url_path` | string | Path of the KME permalink, e.g. `/nintedanib/` |
| `link` | string | Full KME permalink |
| `template` | string | `_wp_page_template` (e.g. `page-templates/full-width.php`), or `""` for the default |
| `content_raw` | string | Raw `post_content`: serialised block markup, **exactly as stored** |
| `date_gmt`, `modified_gmt` | ISO 8601 \| null | |
| `author` | `{login, email}` \| null | The full record is in `users.json` |
| `featured_media` | int \| null | `source_id` of the attachment |
| `attachments` | int[] | `source_id`s of child attachments |
| `meta` | `{key: value[]}` | Values are **always lists**, because a key can hold several rows. Includes every non-protected key, plus `_nhs_*` (display settings: hero, breadcrumbs, sidebar …), `_members_*` (access rules), `_thumbnail_id` and `_wp_attachment_image_alt`. |
| `acf` | `{field: value}` | ACF values by **field name**, unformatted: relationships are lists of `source_id`s, dates are `Ymd` strings, wysiwyg is the saved HTML, and repeater rows are keyed by sub-field name. The raw ACF meta rows (`blocks_0_type`, `_blocks_0_type` …) are left out of `meta`. |
| `terms` | `{taxonomy: slug[]}` | |
| `original_post_id` | int \| null | For a pending "Ready for Live" copy, the live post it will replace. `null` otherwise. |
| `embeds` | object[] | See below |
| `links` | string[] | Unique internal hrefs: relative, or pointing at the KME host |
| `blocks_used` | `{block: count}` | Including nested blocks |
| `rendered` | `{path: html}` | See below |
| `presets_used` | string[] | Titles named by `create-block/todo-list` blocks, in order of use |
| `revisions` | object[] | `{revision_id, date_gmt, author, title, excerpt, content_raw}`, oldest first |
| `archives` | object[] | See below. Empty except on posts. |
| `content_sha256` | hex string | See "Change detection" |

### Block paths

Several fields locate a block in the tree with a **path**: the block's index at each nesting
level, joined by dots, as returned by `parse_blocks()`. `"4"` is the fifth top-level block;
`"12.0.1"` is the second child of the first child of the thirteenth top-level block.
Freeform (null-name) blocks count as positions.

### `embeds`

Every embed in the block tree, in document order:

| `block` | Extra fields |
|---|---|
| `nhs/ndo-widget` | `attrs` (all block attributes, e.g. `resourcePath`, `aspectRatio`) |
| `nhs/metabase-widget` | `attrs` (e.g. `resourceType`, `resourceId`, `customHeight`) |
| `create-block/kme-embed` | `src` (the block's `iframe_url`) |
| `nhs/iframe` | `src` |
| `core/embed` | `src` (the embed URL) |
| any other block, or `freeform` | `src` for each raw `<iframe>` in its saved HTML |

### `rendered`

KME's own rendered HTML (`render_block()`) for **leaf blocks that have no saved HTML**, i.e.
blocks built on the server. The importer uses it as a fallback for blocks it can't map.
- Containers aren't rendered, because rendering a parent would also render its children.
  They can be rebuilt from their children.
- **`create-block/kme-embed`, `nhs/ndo-widget` and `nhs/metabase-widget` are never
  rendered.** Their output contains signed tokens, and their attributes are enough to rebuild them.
- `create-block/todo-list` renders its preset's HTML, which is how a preset missing from
  `options.json` can still be kept.
- As a safety net, token-like URL parts in any rendered HTML are replaced with `[removed]`:
  `token=`, `secret=`, `signature=`, `access_token=`, and Metabase `/embed/{question|dashboard}/<jwt>`.

### `archives`

KME's "Ready for Live" workflow publishes an edit by archiving the live post (status
`archive`, slug `<slug>-archived-<time>`, title `<title> - Archived on <date>`) and
republishing the edited copy under the original slug, with `_original_post_id` pointing at
the archive. Following `_original_post_id` back from a live post gives its earlier versions.

- The chain **stops at the first archive whose slug doesn't start with `<live slug>-archived-`**.
  Reports for a new molecule are started by duplicating an old molecule's, which copies
  `_original_post_id`, so without the check a new report would inherit another report's history.
- Each entry: `{archive_id, date_gmt, archived_gmt, author, title, excerpt, content_raw, acf}`,
  oldest first. `title` has the " - Archived on …" suffix removed. `content_raw` is as archived:
  Ready for Live had already flattened its preset blocks into `core/paragraph` HTML.
- Archives that no exported post chains to are listed in the manifest's `orphan_archives`.

## Options (`options.json`)

- `presets[]`: `{title, text, sha256}`, in KME's order. `text` is the wysiwyg HTML as stored
  (no `<p>` wrapping). `sha256` covers the title and text.
- `presets_sha256`: a hash over the whole list.

## Media (`media.json` and `files/`)

One entry per attachment: `source_id`, `file` (the path inside the bundle, `files/{id}/{basename}`),
`filename`, `mime_type`, `title`, `alt`, `caption`, `parent`, `url` (the KME URL, for
rewriting references in content), `filesize`, `sha256` and `missing`.
If the file wasn't on disk when the bundle was built, `missing` is `true`, the size and hash
are `null`, and there's no file in `files/`.

## Manifest (`manifest.json`)

- `schema`, `schema_version`, `generator {name, version}`, `generated_at`.
- `site`:
  - `name`, `home`, `siteurl`, `wp_version`
  - `page_on_front` and `page_for_posts`: `source_id`s, `0` if unset
  - `permalink_structure`
  - `theme {stylesheet, version}`.
- `counts`: `items` (by type, then status), `revisions`, `media_files`, `media_missing`,
  `archives_chained`, `archives_orphaned`, `pending_copies`, `presets`.
- `change_index`: `{source_id, type, status, parent, modified_gmt, content_sha256}` for every item.
- `orphan_archives`: `{id, title, parent}` for each archived post not in any item's `archives`.

## Change detection

`content_sha256` is a SHA-256 over the fields the importer writes:
- `type`, `status`, `slug`, `title`, `parent`, `menu_order`, `template`
- `content_raw`, `excerpt`, `featured_media`, `meta`, `acf`, `terms`, `original_post_id`
- the lists of revision IDs and archive IDs.

Presets have their own `sha256` in `options.json`.

It deliberately leaves out values that change without an edit (`link`, dates, computed lists).
On a re-import, an item whose hash matches the last imported hash has nothing to update.

## Producing a bundle

- **wp-admin:** Tools → Export → **KME** → **Download Export File** gives
  `kme-export-<site>-<YYYYmmdd-HHMMSS>.zip`. It's admin-only (`manage_options`); like
  WordPress's own exports it's a plain link from the Export screen. It's built in a temp file,
  which is deleted after streaming.
- **WP-CLI:**
  - `wp kme-export build --out=<dir>` writes a zip.
  - `--dir` writes an unzipped folder.
  - `--anonymise-authors` replaces author identities.
  - `--include=<ids>` limits the bundle to those items plus their attachments.
  - `--max-revisions=<n>` keeps each item's newest n revisions.
- **The committed fixture** (`fixtures/bundle-v1/`) is produced with `--dir
  --anonymise-authors`, plus `--include` / `--max-revisions` to keep it small.
