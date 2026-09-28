# Contract fixture: `bundle-v1/`

A small, real KME export bundle (`schema_version` 1.0) in unzipped form. SPS2's importer
tests use a copy of it (`sps2/tests/fixtures/kme-bundle-v1/`), so the two repos are tested
against the same data.

It was generated from the local copy of KME (`kme.test`, content to 2026-08-13) on
2026-09-28 with:

    wp kme-export build --out=fixtures/bundle-v1 --dir --anonymise-authors \
      --include=1496,2025,2468,4911,8542,9147,9241,9306,9470,9481 --max-revisions=2

| Item | Why it's included |
|---|---|
| 1496 Key Molecules Expenditure | Front page: four ACF collections (cards, simple cards), collection text with links to KME's search page |
| 9470 Nintedanib, 9241 Omalizumab, 9147 Denosumab 120mg | Molecule pages: two collections of pinned reports each |
| 9481 Check performance of nintedanib for Trusts | A report: preset blocks, 7 chart embeds, `date_extracted`, `parent` |
| 9306 Check performance of omalizumab for Trusts | A report whose ACF `parent` (Denosumab 120mg) disagrees with the page that pins it (Omalizumab) |
| 4911 Progress charts, 8542 Compare progress for Trusts | A listing page and a report with 9 chained "Ready for Live" archives |
| 2468 Why and how we monitor KME | A text block with KME's automatic contents list |
| 2025 Search | The Bricks search page (a page SPS2 excludes) |
| 1561 | Attachment: the file is in `files/1561/` |

- **Authors are anonymised** (`author-{id}@example.invalid`).
- **Revisions are capped at 2 per item.** Archived versions are not capped. That makes
  `content_sha256` differ from a full export of the same site, which is expected.
- `options.json` holds all 72 preset texts; `orphan_archives` lists every archive the
  included items don't chain to.
- **To regenerate it after a contract change,** run the command above against a local KME copy
  (with `Key-Molecules-Plugin` active). Then update SPS2's copy and note the exporter version
  in its README.
