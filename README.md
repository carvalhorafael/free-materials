# Free Materials

Free Materials is a WordPress plugin that owns a reusable "free materials" content domain. It registers the custom post type, taxonomy and editorial metadata needed to publish downloadable resources while leaving visual presentation to the active theme.

## What It Provides

- Custom post type: `material_gratuito`
- Custom taxonomy: `material_categoria`
- REST-enabled metadata:
  - `_executive_signal_material_capture_label`
  - `_brevo_leads_capture_list_id` (legacy storage key for the capture destination ID)
  - `_brevo_leads_capture_delivery_url` (legacy storage key for the delivery URL)
- Rewrite rules for `/materiais-gratuitos/` and `/materiais-gratuitos/categoria/...`
- GitHub Releases update integration through the plugin `Update URI`

## What It Does Not Provide

This plugin does not render a public front end, does not process lead capture form submissions and does not provide a lead capture settings UI. Themes and integration plugins should consume the content domain and decide how to display or process it.

For example:

- A theme may provide `single-material_gratuito.php` and `taxonomy-material_categoria.php`.
- A lead-capture plugin may process `admin-post.php` submissions and read the capture metadata.

## Public Contract

The plugin keeps these identifiers stable so existing WordPress content remains portable:

```php
free_materials_post_type(); // material_gratuito
free_materials_taxonomy(); // material_categoria
free_materials_cta_label_meta_key(); // _executive_signal_material_capture_label
free_materials_capture_destination_meta_key(); // _brevo_leads_capture_list_id
free_materials_delivery_url_meta_key(); // _brevo_leads_capture_delivery_url
```

The `_brevo_*` meta key names are preserved for backward compatibility with existing sites. New integrations should use the generic helper functions instead of coupling to a specific CRM name.

## Installation

1. Download the latest `free-materials-X.Y.Z.zip` release asset.
2. In WordPress admin, go to Plugins > Add New > Upload Plugin.
3. Upload and activate the ZIP.
4. Flush permalinks if needed by visiting Settings > Permalinks and saving.

## Development

Requirements:

- PHP 8.1+
- Composer
- MySQL for WordPress integration tests
- Subversion for installing the WordPress PHPUnit test suite

Install dependencies:

```bash
composer install
```

Run unit tests:

```bash
composer test:unit
```

Install the WordPress test suite and run integration tests:

```bash
composer install:wp-tests
composer test:wordpress
```

Run the full test suite:

```bash
composer test
```

Build a public ZIP package:

```bash
composer package
```

## Release Flow

Releases are prepared from `develop` and published when the prepared version reaches `main`.

1. Run the `Prepare Release` workflow with `patch`, `minor`, `major` or an explicit version.
2. Merge the generated `release/vX.Y.Z` PR into `develop`.
3. Merge `develop` into `main`.
4. The `Release` workflow validates the plugin, creates tag `vX.Y.Z`, publishes a GitHub Release and uploads `free-materials-X.Y.Z.zip`.

## License

GPL-2.0-or-later.
