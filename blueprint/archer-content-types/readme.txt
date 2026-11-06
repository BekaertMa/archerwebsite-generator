=== Archer Content Types ===
Contributors: archerwebsites
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later

Custom content types without code: realisaties, projecten, referenties, wagens, vacatures or anything else.

== Description ==

Settings > Content types (Instellingen > Inhoudstypes) lists the content types of the site. Each type has:

* a singular and plural name, an icon and an address (/wagens/<item>/);
* fields: text, several lines, number, price, date, web address, email, phone, choice from a list, yes/no, image, gallery;
* category lists (taxonomies) to group or filter items, such as a brand or a service;
* a "Details" box on the edit screen with those fields.

Start from an example (realisaties, projecten, referenties, wagens, vacatures, team) or build one from scratch.

= Bricks =

* Overview: a query loop on the post type. Single item: a template with the condition "post type".
* Every field is available as dynamic data {act_<field>}: dates and prices are formatted, image and gallery fields work in image and gallery elements. {cf_<field>} also works for plain text.
* When a published page uses the same address as the type (for example /realisaties/), that page stays the overview; otherwise WordPress shows an automatic archive.

= REST API (Archer builder) =

Administrators only.

* GET /wp-json/archer-ct/v1/types
* POST /wp-json/archer-ct/v1/types — {"types":[{...definition...}], "replace": false}
* DELETE /wp-json/archer-ct/v1/types/<key>
* POST /wp-json/archer-ct/v1/template — {"type":"wagen","title":"Wagen"} → {"id":123}: the Bricks content template for one item.
* POST /wp-json/archer-ct/v1/items — {"type":"wagen","items":[{"uid":"...","title":"...","excerpt":"...","content":"...","status":"draft","image":"https://...","meta":{"prijs":12500,"galerij":["https://..."]},"terms":{"wagen_carrosserie":["SUV"]}}]}

Items are matched on "uid". Publishing again updates an item only while nobody changed it on the site; an edited item is reported as "kept". Image and gallery values accept attachment IDs or URLs (downloaded once into the media library). Types created through the API have source "archer"; "replace" only removes those, never types made on the site.

= Hooks =

* archer_ct_types (filter) — definitions before registration.
* archer_ct_post_type_args (filter) — register_post_type() arguments per type.
* archer_ct_presets (filter) — the examples on the settings screen.

== Changelog ==

= 1.3.0 =
* POST /archer-ct/v1/filters: turns on Bricks query filters and indexes the filter elements of the given pages (search and filters above a list of items).

= 1.2.0 =
* POST /archer-ct/v1/megamenu: Bricks mega menu templates for the top-level items of a menu.

= 1.1.0 =
* POST /archer-ct/v1/template: a Bricks content template per type (condition: post type), used by the Archer builder for the page of one item.

= 1.0.0 =
* First version.
