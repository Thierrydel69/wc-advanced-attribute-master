# WooCommerce Advanced Attribute Master

A WordPress plugin to manage WooCommerce product attributes and categories from a powerful tabular interface.

## Features

- **Tabular interface** — View and edit all products with their attributes in a single table
- **Column selector** — Choose which attributes and categories to display
- **Smart autocomplete** — Type-ahead suggestions from existing WC attribute terms, case and accent insensitive
- **Create on the fly** — Type a new value and it will be created as a WC term automatically
- **Multi-value support** — Add multiple terms per attribute per product
- **Paste from CSV/Excel** — Paste an entire column of values onto selected products at once
- **Bulk apply** — Define a master row and apply values to all selected products
- **Row-level save** — Each product row has its own Save button (only active when changed)
- **Undo/Rollback** — Cancel changes before or after saving (5-level history)
- **Sorting** — Click the Product column header to sort A→Z or Z→A
- **Pagination** — 20 / 50 / 100 products per page
- **Search** — Filter products by name with debounce
- **REHub Attribute Groups fix** — Companion plugin fixes display of free-text attributes in REHub theme groups (see below)

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 7.4+

## Installation

### Via WordPress admin

1. Download the latest ZIP from [Releases](../../releases)
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP and click **Install Now**
4. Activate the plugin

### Via FTP

1. Unzip and upload the folder `wc-advanced-attribute-master/` to `/wp-content/plugins/`
2. Activate from the **Plugins** screen

### Access

After activation: **WooCommerce → Attribute Master**

## REHub Attribute Groups Fix (optional)

If you use the **REHub theme** with its Attribute Groups feature, free-text attributes (like "Advantages", "Disadvantages", "Features") may fall into the generic "Specifications" group instead of their configured group.

This is a bug in REHub's `rh_get_attributes_group()` function which compares `pa_avantages` (WC slug) with `Avantages` (attribute name) — they never match.

**Install the companion plugin** `wcaam-rehub-fix.zip` to permanently fix this. It hooks into WooCommerce's native `woocommerce_product_get_attributes` filter and is update-proof (survives REHub updates).

## How it works

### Loading products
The plugin loads products via AJAX using `WP_Query` on `post_type=product`, compatible with all WooCommerce product types (simple, external, variable, custom types including REHub's external type).

### Saving attributes
- **Taxonomy attributes** (`pa_color`, etc.) — uses `wp_insert_term()` + `wp_set_object_terms()`
- **Free-text attributes** (long text stored as `is_taxonomy=0`) — writes directly to `_product_attributes` post meta
- Duplicate prevention: case-insensitive + accent-insensitive comparison before creating terms

### Security
- All AJAX endpoints use WordPress nonces (`check_ajax_referer`)
- Capability check: `manage_woocommerce` required
- All inputs sanitized with `sanitize_text_field()` / `sanitize_key()`

## File structure

```
wc-advanced-attribute-master/
├── wc-advanced-attribute-master.php   Main plugin file (PHP)
├── wcaam-main.js                       All JavaScript (vanilla JS + jQuery)
└── wcaam-rehub-fix.php                 Optional REHub companion fix
```

## License

GNU General Public License v2.0 — see [LICENSE](LICENSE)

## Contributing

Pull requests welcome. Please open an issue first to discuss what you would like to change.

## Changelog

See [CHANGELOG.md](CHANGELOG.md)
