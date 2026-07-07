=== Free Materials ===
Contributors: carvalhorafael
Tags: custom-post-type, content, resources, downloads
Requires at least: 6.4
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reusable WordPress content domain for free materials and downloadable resources.

== Description ==

Free Materials registers a portable WordPress content domain for publishing downloadable resources. It owns the custom post type, taxonomy and editorial metadata while allowing themes to handle presentation.

The plugin registers:

* `material_gratuito` custom post type.
* `material_categoria` taxonomy.
* Lead capture metadata for button text, capture destination ID and delivery URL.

== Installation ==

1. Upload the plugin ZIP through Plugins > Add New > Upload Plugin.
2. Activate Free Materials.
3. Save Settings > Permalinks if rewrite rules need to be refreshed.

== Frequently Asked Questions ==

= Does this plugin render the public material pages? =

No. The active theme should provide templates and styling. This plugin owns the portable content model.

= Does this plugin submit leads to a CRM? =

No. It stores portable lead capture metadata for each material. A separate capture plugin can read those fields and process submissions.

= Does this plugin provide lead capture settings in the material editor? =

No. Lead capture settings belong to the active capture integration plugin.

== Changelog ==

= 0.1.1 =

* Removed the plugin-owned lead capture meta box from the material editor.
* Added generic helpers for capture metadata while preserving legacy meta keys.

= 0.1.0 =

* Initial public plugin foundation.
