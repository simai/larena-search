# Implementation Summary

- Added the `ReindexSourceFactory` contract and static compatibility adapter.
- Changed the singleton registry to store factories and resolve sources lazily
  without caching created instances.
- Added resolution-free `has()` and deterministic `providerIds()`.
- Changed scheduling to use provider metadata only.
- Resolve each source immediately before its batch read.
- Reject factory/source provider identity mismatch.
- Sanitize arbitrary factory construction failures at the reindex boundary.
- Preserved legacy `register(ReindexSource)` and `all()` behavior.
