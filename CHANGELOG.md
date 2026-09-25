# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-09-25

First release as a standalone plugin. The abilities, their schemas, defaults and permission checks are those of the release it was forked from (see the history below); what changes is how the plugin is built, named and distributed.

### Added

- A small autoloader (`includes/Autoloader.php`) mapping `WpMcpAbilities\Foo\Bar` to `includes/Foo/Bar.php`. The plugin loads without Composer.
- Explicit wiring in `Plugin::boot()`: every service and hook is built once, in one place. `Plugin::ABILITIES` lists the 36 static ability classes; the post type abilities are still generated at runtime.
- `Registry\AbilityRegistry`, which registers the ability category on `wp_abilities_api_categories_init` and the static abilities on `wp_abilities_api_init`.
- `Contracts\AbilityInterface`, `Contracts\AbilityCategoryInterface` and `Contracts\HookInterface`.
- The `wpmcpa_abilities` filter, applied to the static abilities just before registration. The plugin hooks it at priority 20 to drop abilities that are switched off or miss a dependency.
- The MCP Adapter is optional. Without it, the abilities are still registered through the core Abilities API and usable by any other consumer. The admin notice becomes a warning, shown only on the Plugins screen and the plugin's settings screen, links to the adapter's GitHub releases, and suggests `wp plugin activate mcp-adapter` when the adapter's folder is already there. A missing Abilities API is still reported as an error, on every admin screen.
- `readme.txt` for WordPress.org, the full GPLv2 text in `LICENSE`, `.distignore` for the distributed package, and `.wordpress-org/` for the directory assets.
- Continuous integration (PHP syntax check, PHPCS, Plugin Check on the built package) and deployment to WordPress.org on `v*` tags, guarded by `bin/check-versions.sh`.
- `phpcs.xml.dist` with the WordPress Coding Standards, PHPCompatibilityWP (PHP 8.1 and later), the minimum WordPress version and the prefix and text domain checks, run by `composer lint` and `composer format`.
- The `wpmcpa_register_abilities` action, fired once on first use of the registry (at the earliest during `init`), to add custom abilities extending `AbstractAbility`. They get a switch on the settings screen and go through the execution hooks. Objects that do not extend `AbstractAbility` are refused with `_doing_it_wrong()`; a later ability with the same name replaces the earlier one.
- The `wpmcpa_before_execute` action, fired before an ability of the plugin runs, once its permission is granted. Abilities now run through `AbstractAbility::run()`, which calls `execute()`.
- The `wpmcpa_execute_result` filter on the result of every ability of the plugin. A value other than an array or a `WP_Error` is ignored and reported with `_doing_it_wrong()`.
- The `wpmcpa_after_execute` action, fired after an ability of the plugin ran, with its final result.
- The `wpmcpa_ability_properties` filter on the arguments each ability hands to `wp_register_ability()`.
- The `wpmcpa_loaded` action, fired at the end of `Plugin::boot()` with the registry.

### Changed

- Plugin slug, folder and text domain: `wp-mcp-abilities`. Main file: `wp-mcp-abilities.php`. Display name: WP MCP Abilities.
- PHP namespace: `WpMcpAbilities`. Constants: `WPMCPA_VERSION`, `WPMCPA_FILE`, `WPMCPA_DIR`.
- Ability category and name prefix: `wp-mcp-abilities`, so abilities are named `wp-mcp-abilities/<name>`.
- Filters: `wpmcpa_*`, with the same suffixes as before.
- Options: `wpmcpa_enabled` and `wpmcpa_exposed`, both deleted on uninstall. Existing settings are not migrated.
- Mark carried by every ability of the plugin: `meta.wpmcpa.plugin`.
- Error code of the WooCommerce permission probe: `wpmcpa_permission_probe`. Settings page slug: `wp-mcp-abilities`.
- `Requires at least: 6.9`, the first version shipping the Abilities API in core (was 6.8). `Tested up to: 7.1`.
- No `Requires Plugins` header: the MCP Adapter is not hosted on WordPress.org, so the header could not name it.
- Composer package: `fyrins/wp-mcp-abilities`, with no runtime dependency. The MCP Adapter is no longer pulled in by Composer.
- Translations are loaded just in time by WordPress. The POT file was regenerated and the French translation updated.
- README and changelog rewritten in English.

### Removed

- The dependency on the former dependency-injection framework: kernel, service container, `config/services.php`, container cache and kernel name constant.
- The private Composer repository the framework was installed from.
- The `load_plugin_textdomain()` call.
- `Support\Requirements::areMet()`, which nothing called any more.

## Pre-standalone history

The entries below come from the plugin this project was forked from, bsaweb-mcp-abilities, and are kept for reference. Names are updated to the current prefixes: `wp-mcp-abilities/` for abilities, `wpmcpa_` for filters and options. Before 2.0.0, abilities used the `wordpress/` prefix; entries for those releases give ability names without any prefix.

### bsaweb-mcp-abilities 2.5.1 - 2026-09-14

#### Fixed

- The settings screen no longer resolves the abilities registry on every admin screen.

  `registerSettings()` runs on `admin_init`, so everywhere in the admin, and ended by declaring the third-party sections. Building them asks the catalogue for its inventory, which resolves the registry. Resolving it that early froze it before plugins registering later had their turn: the MCP Adapter could no longer find its own abilities and gave up creating its default server, raising a notice. Wherever an error handler promotes notices to exceptions, as some development setups do, that notice was fatal: the block editor and the dashboard answered with a 500 error and the site could no longer be edited.

  These sections are only read on the settings screen, so they are now declared only when that screen is shown or saved. `register_setting` still runs everywhere, as `options.php` needs it to write the option.

### bsaweb-mcp-abilities 2.5.0 - 2026-09-02

#### Fixed

- `replace-in-post-content` searches for and writes exactly what it was asked.

  `search` and `replace` went through `sanitize_text_field()`, although the ability exists to handle literal strings carrying block markup. That function strips tags, HTML comments and newlines and collapses whitespace: it defended nothing and rewrote the question. A search starting with `<` came back empty and was refused with `empty_search`, blaming the caller for an internal fault. Searching `<h1 class="wp-block-heading">` matched a bare `<h1>`, and a replacement lost its class, producing `<h1 …>…</h2>`. A `\n` never survived, so no block boundary could serve as an anchor. In every case the response reported `replaced: true` and a plausible `occurrences`.

  Both parameters are now read by `Input::literal()`, which returns the value as sent. It is not unslashed either: the MCP Adapter serves abilities over the REST API, whose parameters arrive decoded from JSON, and unslashing would eat backslashes the caller really sent, turning `\u002d` escapes into a bare `u002d`. What the user may write is still settled by the `edit_post` check and by kses on the way into the database.

- `date` was silently ignored on creation.

  `date` and `sticky` were only in the `update` schema, and the MCP Adapter drops properties the schema does not declare: a `date` sent to `create` disappeared and the post came out dated today. Importing nine dated posts took eighteen calls instead of nine. Both properties are now accepted on creation as on update. An unreadable date is refused with `invalid_date` instead of reaching `post_date`, where WordPress turned it into a zero timestamp.

#### Added

- `terms` on `create-*` and `update-*`: the terms to assign, keyed by taxonomy.

  Only `category` was reachable, through `category_ids`. The plugin could create and edit terms but not attach one to a post, so a content model built on custom taxonomies was out of reach. The schema lists the post type's taxonomies by name rather than relying on `additionalProperties`, so an agent reading it knows which exist. Taxonomies declaring `show_in_rest` are retained; the `wpmcpa_assignable_taxonomies` filter adjusts the list. Terms are assigned with `wp_set_object_terms()` after the write rather than through `tax_input`, which silently drops any taxonomy the user may not assign in. Each taxonomy sent replaces the post's terms in it; a taxonomy left out is untouched. `category_ids` is still accepted.

- `get-*` returns `terms` and `date`, where the output only showed `category_ids`.

#### Security

- Pinning a post now requires a capability. `sticky` was applied behind the `edit_post` check alone, which a contributor passes on their own draft. `sticky_posts` is a site-wide option, and the core REST controller refuses with `rest_cannot_assign_sticky` unless the user has `edit_others_posts` or `publish_posts`. Without that guard, a contributor could pin a draft and see it jump to the front page as soon as an editor published it. Only pinning is guarded, as in core.

#### Changed

- Term abilities and assignable taxonomies now follow the same rule. Terms were listed for `public` taxonomies and assigned for those declaring `show_in_rest`: a private taxonomy exposed to REST, such as an event venue, was assignable while its terms stayed invisible. Both sides now retain `show_in_rest`. Internal taxonomies declaring that flag (`nav_menu`, `wp_pattern_category`) stay reachable, protected by their own capabilities, by `delete-term` being off by default and by the settings switches. **Behaviour change:** such private taxonomies enter the scope of the term abilities, and `post_format`, which does not declare `show_in_rest`, leaves it. The `wpmcpa_taxonomies` filter still narrows the scope.
- `create-*` and `update-*` return the `status` and `date` actually stored. `wp_insert_post()` schedules a `publish` dated more than a minute ahead as `future`, and a bare `success` let callers believe the post was online.
- Sending `category_ids` and `terms.category` in the same call is refused (`ambiguous_categories`) instead of `terms` silently overwriting the other.
- A degenerate date no longer slips through. The first guard relied on `strtotime()` and `get_gmt_from_date()`, which accept `0000-00-00 00:00:00` and fall back on the epoch; that placeholder, which imports and exports produce for undated drafts, landed as a `post_date_gmt` of `-0001-11-30 00:00:00`. The date is now parsed strictly and only accepted when it survives a round trip unchanged.
- A term assignment error carries the `post_id` and `post_written: true`, so the caller can reconcile when the ground moved under the write (a term deleted meanwhile, a third-party filter on `wp_set_object_terms`).
- `get-*` reads all terms in one query instead of one per taxonomy, and derives `category_ids` from it.
- Refusals come before the write. Unknown taxonomy, missing term, missing `assign_terms`, unreadable date: all of it is checked before `wp_insert_post()`, so a refusal never leaves a post behind a response reporting a failure.
- `replace-in-post-content` tells a missing parameter (`missing_search`) from an empty string (`empty_search`).
- Internal: the taxonomy rules moved out of `PostTypeAbility` into `PostTypeTaxonomies`, which can be reasoned about from a post type alone. No behaviour change.
- The README documents `terms`, `date` and `sticky`, the literal guarantee of `replace-in-post-content`, the shared `show_in_rest` rule, and how to enable a `delete-*` ability for a session.

#### Removed

- Working documentation (design specs, acceptance recipes, review reports) left the repository and was no longer distributed with the plugin.

### bsaweb-mcp-abilities 2.4.0 - 2026-08-24

#### Added

- The settings screen can expose to MCP the abilities another plugin keeps closed.

  2.2.0 could take a third-party ability out of an agent's reach, never the reverse. A plugin may register abilities without meaning them for an agent: SEOPress 10.1 registers twenty-one, written for the core REST API, none carrying `meta.mcp.public`. They were invisible and could not be run, although they cover redirections, technical audits and title generation.

  That flag cannot be set from the outside, and it does not need to be: it only governs the adapter's three generic abilities, and an ability named in a server's `tools` list becomes an MCP tool without it. The plugin therefore declares the checked abilities to the default server through the `mcp_adapter_default_server_config` filter, without touching the registry or anybody's metadata.

  These boxes are unchecked by default, the opposite of the 2.2.0 switches: here checking opens what a plugin left closed, and an update must never widen on its own what an agent can reach. An exposed ability appears in `tools/list` and answers `tools/call` under a name derived from its own; `mcp-adapter/execute-ability` still refuses it. Exposing grants no right: the ability's own permission check decides. The screen says so, and flags the abilities that declare themselves destructive.

  The state lives in its own option, `wpmcpa_exposed`, separate from the switches, since the two settings have opposite defaults. A removal decided by the switches wins over an exposure.

### bsaweb-mcp-abilities 2.3.0 - 2026-08-21

#### Added

- Nine WooCommerce abilities, where WooCommerce does not describe its own catalogue: `wp-mcp-abilities/create-product`, `update-product`, `list-product-variations`, `get-product-variation`, `create-product-variation`, `update-product-variation`, `delete-product-variation`, `list-product-attributes` and `create-product-attribute`.

  `product` being a public post type, a shop used to inherit the generic CRUD, which only handles a post: title, content, status, taxonomies, featured image. Price, sale, stock, SKU, attributes and variations were out of reach, and an agent creating a product delivered a shell a human had to finish in the admin.

  Since 10.9, WooCommerce covers reading, listing and deleting a product with its own abilities (`woocommerce/products-query` and neighbours). This plugin does not duplicate them; it covers variations, global attributes and creating a variable product, a type the canonical set does not accept.

  These abilities write no field themselves: they hand over to the WooCommerce REST controllers. Input schemas are derived from those controllers, and business rules and capability checks stay WooCommerce's, so a field added by a future version appears without a change here. Deleting a variation is disabled by default, like every deletion of the plugin.

- The catalogue abilities accept `_fields`, the REST API field selection. A product has more than seventy properties. The selection is applied by the plugin itself, since `rest_filter_response_fields()` hooks `rest_post_dispatch`, which `rest_do_request()` does not fire.

- A global attribute created by `create-product-attribute` can be used at once, including in the request that created it. WooCommerce registers `pa_*` taxonomies on `init`, from the database, so values assigned to a younger attribute were dropped without a message. Each MCP call is normally its own HTTP request, but JSON-RPC allows batches.

#### Fixed

- `Support\Input::int()` no longer casts what is not a number. PHP turns a non-empty array into `1` without a warning, so an identifier arriving as an array silently targeted the object with ID 1. The default value is returned instead. The helper serves every family of abilities.
- Invalid input is no longer reported as a permission refusal. The REST server validates before the filter the permission probe hooks, so a malformed payload stopped the request early and the answer "forbidden" sent the agent looking for rights it already had. The probe now retries on the bare route, and WooCommerce's validation error comes back as is.
- `GET` requests pass their parameters again. They were set in the body, which `WP_REST_Request` only reads for `POST`, `PUT`, `PATCH` and `DELETE`, so a filtered read went out without its filter.
- A list's total is no longer made up when WooCommerce sends no pagination headers. Falling back on the page size told the agent it held everything.

#### Changed

- When WooCommerce runs, `product` leaves the generic CRUD for creation and update, which the dedicated abilities take over under the same names. From WooCommerce 10.9, reading, listing and deleting leave it too; below that, they stay generic. The split goes through the new `wpmcpa_post_type_operations` filter, which releases one operation without releasing the whole post type.
- `Support\Capabilities::canCallRestRoute()` passes a decision already taken by a REST route through `wpmcpa_check_permission`, so an integrator keeps the last word.

### bsaweb-mcp-abilities 2.2.0 - 2026-08-21

#### Added

- The abilities registered by other plugins appear on the settings screen, grouped by plugin, with a switch each.

  The screen only listed this plugin's abilities, while a production site registers many more, from WP Rocket, Imagify or WooCommerce for instance. All of them carry `meta.mcp.public` and can be run through `mcp-adapter/execute-ability`: an agent could clear a site's cache or change a performance setting with no screen offering to prevent it. They stay enabled by default, since unchecking them on update would break integrations in place.

  Removal uses `wp_unregister_ability()`, the only lever the Abilities API offers, and only happens on the routes served by the MCP Adapter, under `/wp-json/mcp/`, on `rest_pre_dispatch`. Hooking `wp_abilities_api_init` instead would have tied the switches to whoever resolves the registry first: one plugin calling `wp_get_abilities()` on `init` would have made them decorative without a word. Targeting MCP rather than REST as a whole matters as much: a plugin often calls its own abilities from its admin screens, over REST.

  One limit, measured on adapter 0.5: the default server freezes the abilities it exposes as resources and prompts when it is built. A third-party ability declaring `meta.mcp.type` as `resource` or `prompt` stays listed once unchecked, although running it fails. Tools are not affected.

  A site serving its MCP server elsewhere than under `/wp-json/mcp/` adds its prefix with the `wpmcpa_mcp_route_prefixes` filter.

  Core and adapter abilities are not offered: removing `mcp-adapter/execute-ability` would cut the branch the agent sits on. Neither are this plugin's own, recognised by the `meta.wpmcpa.plugin` mark they now carry: excluding them by name prefix would also have hidden another plugin's abilities sharing that prefix.

### bsaweb-mcp-abilities 2.1.1 - 2026-08-18

#### Fixed

- The `meta_value` schema listed its accepted types with `oneOf`, which requires a value to match exactly one of them. WordPress validates a string against an `array` schema by splitting it on commas, so every string matched both the `string` and the `array` branch and validation failed; numbers and booleans met the same fate. `update-post-meta` and `update-term-meta` refused any scalar value, and `get-post-meta` and `get-term-meta` failed on their output as soon as a `meta_key` was given. The keyword is now `anyOf`. The accepted types are unchanged.

### bsaweb-mcp-abilities 2.1.0 - 2026-08-11

#### Added

- `wp-mcp-abilities/replace-in-post-content`: replaces a literal string in a post's content without the caller resending the whole content. `expected_occurrences` blocks the write when the actual count differs, and `dry_run` counts and measures without writing. The search is a literal string, never a regular expression. The response returns `content_intact`, false when what was stored differs from what was submitted, which points to kses.

#### Fixed

- The post type `create-*` and `update-*` abilities lost one level of backslashes on every write. `wp_insert_post()` and `wp_update_post()` expect slashed data, and `PostTypeAbility` called them without `wp_slash()`, unlike the template abilities. Rewriting a page through MCP turned the unicode escapes of block markup into a bare `u002d`, breaking every CSS variable written that way in block attributes and inline styles.

### bsaweb-mcp-abilities 2.0.1 - 2026-08-11

#### Fixed

- The plugin's abilities are visible to, and can be run by, an MCP client. They were registered, but none declared `meta.mcp.public`, which the adapter reads to decide what it exposes and treats as `false` when missing: on a site running the plugin, all 46 abilities were registered and none appeared in the tool list. Recent adapter versions also refuse to run them (`Ability "…" is not exposed via MCP (mcp.public!=true)`). Neither the settings screen nor clearing the container cache could change that.

### bsaweb-mcp-abilities 2.0.0 - 2026-08-10

Major release: the plugin was rebuilt on the former dependency-injection framework and three security flaws were fixed. Upgrading from 1.x requires manual steps, listed at the end of this entry.

#### Security

- Write abilities check the capability bound to the targeted object (`edit_post`, `delete_post`, `edit_term`, `delete_term`) instead of the global `edit_posts`. A Contributor can no longer edit or delete another author's content, nor create or delete terms.
- Publishing or reassigning content explicitly requires `publish_posts` and `edit_others_posts`. The editorial workflow can no longer be bypassed through an ability.
- Stored XSS fixed in `update-post-schemas-seopress`: a syntactically valid JSON-LD containing `</script>` produced JavaScript run for every visitor. The JSON is now re-encoded with `JSON_HEX_TAG`, and the `script` tag is no longer allowed by `wp_kses`.
- The meta abilities no longer read, write or delete protected meta (prefixed with `_` or declared protected) without explicit permission. An allow-list, empty by default, is filled through the `wpmcpa_allowed_meta_keys` filter.
- The SEOPress abilities are no longer registered when SEOPress is missing. They used to answer `success: true` while writing orphan post meta.

#### Added

- CRUD for block templates and template parts: `list-templates`, `get-template`, `create-template`, `update-template` and `delete-template`, with a `type` parameter set to `wp_template` or `wp_template_part`. Reads go through `get_block_templates()`, so templates provided by theme files are visible alongside those stored in the database.
- Pagination on every list ability (`per_page` capped at 100, `page`). They used to load the whole table on every call.
- Dependency check on load, with an admin notice saying what is missing and the command to install it. Without the Abilities API the registration hook never fired and no ability existed, silently. The check looks for `wp_register_ability()` rather than at the WordPress version.
- Minimum WordPress version lowered from 6.9 to 6.8, as the Abilities API could come from its feature plugin before it reached core.
- `wordpress/mcp-adapter` ^0.5 declared as a Composer dependency, not bundled in a plugin-level `vendor/`, so projects already pulling it in do not get two copies of the `WP\MCP\*` classes.

#### Changed

- One class per ability under `includes/Abilities/`, discovered by the framework's service container.
- The settings screen goes through the Settings API: one section per group of abilities, one field per ability, declared from `includes/Hooks/Admin/`. Reading the settings and the ability inventory became services under `includes/Services/`, where a single class used to hold the menu, the registration, the validation and the whole page.
- The category abilities (`list-categories`, `create-category`, `delete-category`) are replaced by the generic taxonomy abilities called with `taxonomy: "category"`, which sanitise the name and description correctly.
- Write responses return the full updated object rather than a few fields.
- **Breaking:** the plugin folder and main file were renamed.
- **Breaking:** abilities moved from the `wordpress/` prefix to the fork's own prefix (now `wp-mcp-abilities/`). Connected MCP clients had to be reconfigured.
- **Breaking:** the settings option, PHP namespace, filter prefix and text domain were renamed, without migration: enabled abilities had to be checked again on each site.
- **Breaking:** the plugin required the former dependency-injection framework and only ran on projects providing it.

Upgrading from 1.x: deactivate and delete the 1.x plugin, install 2.0.0, check the wanted abilities again in the settings, and reconfigure MCP clients, since tool names changed prefix.

### bsaweb-mcp-abilities 1.9.0 - 2026-06-05

#### Added

- `slug` (`post_name`) accepted by the `create` and `update` abilities of every post type, sanitised with `sanitize_title()` and generated from the title when omitted. The slug is also returned by `get-{type}`.
- Generic taxonomy and term abilities, with the taxonomy as a parameter (covering `category`, `post_tag` and custom taxonomies):
  - `list-taxonomies`: lists public taxonomies (name, label, hierarchical, post types);
  - `list-terms`: lists the terms of a taxonomy (`hide_empty`, `search`, `parent`);
  - `get-term`: reads a term by ID (name, slug, description, parent, count, taxonomy);
  - `create-term`: creates a term (name, slug, description, parent);
  - `delete-term`: deletes a term (disabled by default, marked destructive).

#### Changed

- `update-term` accepts `parent_id` to move a hierarchical term.

### bsaweb-mcp-abilities 1.8.0 - 2026-06-02

#### Added

- `update-post-schemas-seopress`: replaces the SEOPress Pro Custom JSON-LD schemas (`_seopress_pro_schemas_manual`) of a post. Accepts raw JSON entries or entries already wrapped in `<script type="application/ld+json">`. An empty array clears every manual schema.
- Server-side JSON-LD validation before writing (`invalid_json_ld` when invalid).

### bsaweb-mcp-abilities 1.7.0 - 2026-04-24

#### Added

- `list-posts` and `get-post` return `author_id` and `category_ids`.
- `create-post` and `update-post` accept `author_id` to set or reassign the author.

### bsaweb-mcp-abilities 1.6.0 - 2026-04-01

#### Added

- `update-term-meta`: updates an arbitrary meta field of a term (key and value).
- `get-term-meta`: reads a meta field by key, or returns every `_seo_*` meta when no key is given.
- `delete-term-meta`: deletes a meta field of a term.

### bsaweb-mcp-abilities 1.5.0 - 2026-04-01

#### Added

- `update-post-meta`: updates an arbitrary meta field of a post (key and value).
- `get-post-meta`: reads a meta field by key, or returns every `_seo_*` meta when no key is given.
- A "Post Meta" group in the default switches.

### bsaweb-mcp-abilities 1.4.0 - 2026-03-19

#### Added

- `update-term`: updates the native fields of a term (name, description, slug), for every taxonomy.
- `update-term-seopress`: updates the SEOPress meta of a term (meta title, meta description, canonical, robots, Open Graph, Twitter/X).
- `get-term-seopress`: reads the SEOPress meta of a term.
- The `wpmcpa_update_term_seo_data` filter, to change SEOPress data before a term is updated.
- A "Terms" group on the settings screen.

#### Changed

- **Breaking:** the SEOPress post abilities were renamed to make their origin clear: `update-post-seo` became `update-post-seopress`, and `get-post-seo` became `get-post-seopress`.
- The SEOPress group labels on the settings screen carry the "SEOpress" suffix.

### bsaweb-mcp-abilities 1.3.0 - 2026-03-12

#### Added

- `update-media`: updates the metadata of an existing media (title, alt text, caption).
- The `wpmcpa_update_media_data` filter, to change the data before the update.

#### Changed

- Removed an unneeded `input_schema` from the post type list ability.

### bsaweb-mcp-abilities 1.2.0 - 2026-03-12

#### Added

- **Post type abilities generated at runtime:** the CRUD abilities (list, get, create, update, delete) are generated for every public post type. A custom post type added by another plugin is exposed at once.
- An input schema adapted to each post type: `parent_id` for hierarchical types, `category_ids` when the `category` taxonomy is supported, `sticky` for posts only.
- The `wpmcpa_post_types` filter, to keep post types out of MCP.
- The filters `wpmcpa_list_posts_query_args`, `wpmcpa_list_posts_result`, `wpmcpa_get_post_result`, `wpmcpa_create_post_data` and `wpmcpa_update_post_data`.
- The settings screen adapts its groups and destructive abilities to the detected post types.

#### Removed

- The hard-coded `list-pages`, `get-page` and similar abilities, now generated.

#### Changed

- Internal: defaults merge the generated post type abilities with the static ones, post resolution accepts every public post type instead of a fixed `post` and `page` list, and one post type class replaces the separate post and page classes.
- README rewritten.

### bsaweb-mcp-abilities 1.1.0 - 2026-03-05

#### Added

- `list-categories`: lists every category (read only).
- `list-media`: lists every file of the media library (read only).
- The filters `wpmcpa_list_categories_query_args`, `wpmcpa_list_categories_result`, `wpmcpa_list_media_query_args` and `wpmcpa_list_media_result`.
- Translations loaded with `load_plugin_textdomain()`.

#### Changed

- `update-post` supports title, excerpt, status and categories besides the content.
- `upload-media` validates the URL scheme (`http` and `https` only).

### bsaweb-mcp-abilities 1.0.0 - 2026-03-05

#### Added

- `list-posts`: lists published and draft posts.
- `get-post`: returns the full content of a post.
- `create-post`: creates a post (title, content, excerpt, status, categories).
- `update-post`: updates the HTML content of a post.
- `create-category`: creates a category (name, description, parent).
- `upload-media`: downloads a media file from an external URL.
- `set-featured-image`: sets the featured image of a post.
- `delete-post`: deletes a post (trash or permanent).
- `delete-category`: permanently deletes a category.
- `delete-media`: permanently deletes a media.
- `update-post-seo`: updates the SEOPress meta (title, description).
- A **Settings → MCP Abilities** screen to enable or disable abilities.
- Destructive abilities disabled by default (`delete-post`, `delete-category`, `delete-media`).
- Permission checks on `edit_posts` or `delete_posts` depending on the ability.
- WordPress filters to customise queries, results, data and permissions.
- A declared dependency on `mcp-adapter` through `Requires Plugins`.
- Translatable strings under the `wordpress-mcp-abilities` text domain.
- Cleanup on uninstall (`uninstall.php`).
