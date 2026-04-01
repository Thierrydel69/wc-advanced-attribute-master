# Changelog

All notable changes to this project will be documented in this file.

## [3.4.0] - 2025-03-31

### Added
- Sort products by clicking the "Product" column header (A→Z / Z→A)
- Paste a full CSV/Excel column onto selected products at once
- Tag text truncated with ellipsis, remove button always visible (grid layout)

### Fixed
- Remove (×) button now always visible even on long attribute values

## [3.3.0] - 2025-03-30

### Added
- Sorted autocomplete suggestions (alphabetical, with query matches first)
- Paste multi-values into a single cell (comma/semicolon/tab/newline separators)

### Fixed
- Tag remove button visibility on long values

## [3.2.0] - 2025-03-29

### Added
- REHub Attribute Groups fix (companion plugin)

## [3.0.0] - 2025-03-29

### Changed
- Free-text attributes (avantages, inconvenients, fonctionnalites, fonctionnalites-ia) now stored correctly as `is_taxonomy=0` in `_product_attributes`, matching REHub's native structure
- Loading: reads from `_product_attributes` plain key for long-text attributes

### Fixed
- Attributes no longer cleared on save
- Correct structure preserved for REHub attribute groups

## [2.9.0] - 2025-03-28

### Fixed
- Replaced `define()` with function `wcaam_long_text_attrs()` to prevent fatal errors on concurrent plugin activation

## [2.8.0] - 2025-03-28

### Fixed
- JS file now loaded from plugin directory (avoids file_put_contents permission issues)
- HTTPS enforced for JS file URL

## [2.2.0] - 2025-03-27

### Added
- Rollback button (↩) per row — undo changes before or after saving (5-level history)
- Save history: Cancel after Save restores pre-save state

### Fixed
- Autocomplete cache now fully cleared after new term creation
- Duplicate detection now accent and case insensitive (`normalizeKey()`)
- Terms longer than 120 chars excluded from autocomplete (list artefacts)
- Autocomplete SQL uses `COLLATE utf8mb4_unicode_ci` (case + accent insensitive)

## [2.0.0] - 2025-03-27

### Changed
- JavaScript now served as a real `.js` file from plugin directory (fixes LiteSpeed Cache truncation)
- `WCAAM` config object uses `wp_json_encode` for safe JSON output
- `allAttributes` loaded via AJAX (avoids inline PHP data issues)

## [1.5.0] - 2025-03-26

### Fixed
- `array_values()` added to force JS array instead of object for `allAttributes`
- `getLabelForTaxonomy()` now safe when `allAttributes` is empty

## [1.0.0] - 2025-03-25

### Added
- Initial release
- Tabular interface with column selector
- Tag input with autocomplete (vanilla JS, no Select2)
- Create new WC terms on the fly
- Bulk apply with master row
- Row-level save with dirty tracking
- Pagination (20/50/100)
- Search with debounce
- Nonces, capability checks, input sanitization
