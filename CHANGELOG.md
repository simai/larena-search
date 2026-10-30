# Changelog

All notable package changes are recorded here. The package does not currently publish a production-ready semantic release.

## Unreleased

- `larena/admin` and `larena/ui` are optional (stage 9 of the sites plan): they move to `require-dev` and `suggest`. `/admin/search`, its provider record and the menu entry are composed only where the admin is installed, `/search` only where ui is; without them the package boots with no such route, and the index and `search:reindex` keep working. With them nothing changes. `SearchWithoutAdminTest` boots the provider all three ways.
- A site's public search page reads that site's documents: the new contract `Contracts\PublicSearchScope` gives the access scope from the request (the application binds it over its site resolution); without a binding the page reads `public`, as a single site does. Sites plan step 2.9.
- Indexed search. Search semantics change from a substring to word prefixes, the same rule as Storage's record lists (`SearchText`, after `Larena\Storage\View\StorageViewSearch`), on MySQL and SQLite alike: the query is split on whitespace into terms, and a document matches when every term starts a word of its title or searchable text, in any order (words are runs of letters, combining marks, digits and underscores, lowercased; punctuation separates words). So `chair` still finds "Chair" and "chairs" but no longer "armchair", `oak chair` now finds "Chair, oak", `sku-0001` finds "SKU-0001", `100%` finds "100%" as the word `100`, and a query of punctuation alone (`%%`) finds nothing. A term's words never span the title and the text. `ё` and `е` stay different letters for matching. The public highlight is unchanged.
- New migration `2026_10_22_000101_create_larena_search_document_texts`: the side table `larena_search_document_texts` (`document_id`, `search_text`: the normalized words, written in the document's transaction, rewritten only when they change, removed with the document), with a FULLTEXT index on MySQL (InnoDB's default parser, built with the stopword list off so "the" or "about" are words; created empty with its index, so no FULLTEXT index is ever built over existing rows). It is a table of its own because InnoDB adds no column in place to a table with a FULLTEXT index; `larena_search_documents` keeps its INSTANT column changes. `larena_search_documents` gains `title_key` (VARCHAR(191), `utf8mb4_bin` on MySQL; added `ALGORITHM=INSTANT`) and the index `larena_search_document_order` (`access_scope`, `locale`, `title_key`, `id`; built `INPLACE, LOCK=NONE`). Existing documents are filled by the migration in chunks of 500 by id; a document the previous code rewrites while the migration runs is refreshed by the next `search:reindex`. Rollback drops the table, the index and the column. Queries no longer run `LOWER()` per row: text and sort key are normalized when a document is written. `searchable_text` is still written as before.
- Queries use the indexes. Documents are walked in order along `larena_search_document_order` and checked with a binary `LIKE '% term%'` on the search text; the walk stops when the page is full. On MySQL a query with a word of at least `innodb_ft_min_token_size` characters first checks a window of the first 1000 rows in order (up to 20 000 for deeper pages); when the window does not hold the page and at least 5% of it matched and the page lies within 100 000 rows at that share, the walk goes on in order, otherwise the FULLTEXT index finds the candidates (`MATCH … AGAINST ('+word*' IN BOOLEAN MODE)` with the query's longest word, the LIKE checking them). Every way gives the same hits; the opt-in `composer test:mysql` proves it against the PHP reference and against SQLite. A query whose words are all shorter than the token size (one or two letters) is checked in order, as before.
- Order changes from `title, provider_id, source_ref` (case-insensitive on MySQL, binary on SQLite) to the title's sort key (lowercased, whitespace collapsed, `ё` ordered as `е`, the first 191 characters, byte order) and then the document id: the same on both databases, and the order the index walks.
- Keyset paging, additive: `SearchQuery` takes an optional trailing `after` (a `SearchCursor`, `(title_key, id)` as an opaque URL-safe string; malformed: `search_query_cursor_invalid`), and `SearchPage` carries `nextCursor`. With a cursor the page starts after it and `offset` only numbers the page. The cursor only positions a query: scopes, provider and locale are applied as before, so a forged cursor never shows anything more. `/search` puts the cursor in its next link (`after`, beside `page`); a page reached by number or by the previous link still reads by offset, and the page cap (500) and the offset bound (10 000) stay.
- `composer bench` (`scripts/search-bench.php`) times the public query (public scope, a locale, 20 a page) before (the substring scan) and after on a throwaway database. p50/p95 ms, 100 000 documents. SQLite 3.53: common word page 1 80.8/101.5 → 0.2/0.2; page 500 by offset 96.1/121.4 → 24.0/39.0, by cursor 0.2/0.3; rare word 72.8/82.4 → 30.5/36.1; two words 73.0/82.7 → 32.6/41.3; Cyrillic prefix 76.3/108.8 → 51.5/57.9; a two-letter term that matches nothing 69.9/81.5 → 72.8/81.6. MySQL 8.2 (with another benchmark running on the server): common word page 1 149.3/162.4 → 2.7/3.0; page 500 by offset 223.3/1137.8 → 40.8/41.9, by cursor 6.0/7.7; rare word 121.1/132.7 → 3.6/4.3; two words 124.4/130.9 → 45.8/60.0; Cyrillic prefix 123.4/144.7 → 44.8/47.4; the two-letter term 125.8/146.2 → 83.2/100.2.
- Document the `storage.site_pages` source: how a published Storage entry maps to a Search projection and why its `projection_version` satisfies the revision fence.

### Fixed

- Reindex execution now enforces the same locked transition table in the
  service, Queue worker, CLI and HTTP: run only scheduled, resume only running,
  retry only failed and internal continuation only running. Stale queued
  operations fail closed.
- Reindex Audit now records the exact schedule/run/resume/retry/continuation
  operation on scheduled, started, checkpoint, completion, rejection and
  failure events; retry is no longer reported as resume.
- Existing-run CLI execution requires an explicit
  `--operation=run|resume|retry`.
- The singleton source registry now retains lightweight factories instead of
  request/connection-bound sources. Scheduling and provider enumeration are
  resolution-free, while every processed batch resolves its source from the
  current container scope and rejects factory/source provider mismatches.
- Provider-state migration backfill timestamps now derive from retained reindex
  runs, with a migration-identity fallback, so rollback/reapply is
  deterministic on SQLite and MySQL.

### Added

- Localized `/search` flow with deterministic pagination, empty/no-result/error states, canonical published links and XSS-safe highlighting.
- Protected `/admin/search` provider/run/checkpoint surface with Reader diagnostics, Administrator expected-state mutations and CSRF protection.
- Queue checkpoint delivery, retry/restart continuation and Scheduler adapter for periodic all-provider delivery without synchronous HTTP indexing.
- SIMAI Framework runtime activation through the canonical UI runtime lock; no second frontend system.
- `ReindexSourceFactory`, lazy `registerFactory()`, `has()` and deterministic
  `providerIds()` contracts, plus a static adapter for legacy
  `register(ReindexSource)` callers.
- Database/native persistent Search index, monotonic source-state tombstones and bounded literal query.
- Generic resumable keyset source contracts and registry.
- Durable one-active-provider rebuild runs, permanent provider-level generation fences, generation-safe realtime writes and final cleanup.
- `search:reindex` CLI with separate schedule/run/resume/retry Access operations.
- Sanitized Security Audit lifecycle and transaction rollback on Audit failure.
- SQLite restart, rollback/reapply, stale-resurrection, Access and Audit regression tests.

### Security

- Public presentation projects only title, canonical locator, escaped snippet and provider label; source references, revisions, payload and private fields never reach the view.
- Unsafe absolute/protocol-relative locators, unbounded query/page input and stale expected-state mutations fail closed.
- Factory/source identity mismatch is a stable Search rejection, while
  every factory-thrown exception, including a forged `SearchReindexRejected`,
  is sanitized as `search_reindex_source_failed` before persistence, Audit or
  CLI output.
- Equal-revision content conflicts fail closed, tombstones win equal revisions and database failures cross package boundaries only as `SearchPersistenceFailed`.
- CLI run/resume/retry checks Access before validating run existence or provider.
- First schedule, realtime upsert/remove and final sweep serialize through one database-owned provider fence so a concurrent publication cannot be swept into an equal-revision tombstone.

### Nonclaims

- No frontend, route, REST/MCP, external/vector engine or production-readiness claim is included.
