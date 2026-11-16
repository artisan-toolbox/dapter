# Dapter roadmap

The proposed architecture and phased delivery plan live in the canonical documentation repository at [Dapter architecture and delivery roadmap](../docs/documentation/dapter-roadmap.md).

The first implementation targets a short workflow. Generate a DataSource, define its source, and reference it from a typed headless Vue adapter. The server declares the allowed operations. Later phases add deeper configuration, complex queries, alternative pagination, infinite loading, and source-specific retrieval.

The first local preview slice implements Collection and ordinary SQLite offset listing, source contracts, paging and access declarations, and the headless DataAdapter. Search, filters, selectable sorting, other drivers, URL state, and live updates remain planned. No stable release is available. Keep the complete plan in the documentation repository instead of duplicating it here.
