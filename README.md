# WP MCP Abilities

Content management abilities for AI agents, registered with the WordPress Abilities API and reachable over MCP through the MCP Adapter.

`version 1.0.0` · `license GPL-2.0-or-later` · `WordPress 6.9+` · `PHP 8.1+`

An agent connected over MCP can list, read, create, update and delete posts of every public post type, media, taxonomy terms, post and term meta, block templates and template parts, WooCommerce products, variations and attributes, and SEOPress fields. It does so as a WordPress user, within that user's capabilities, and each ability can be switched off from the settings screen.

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Connecting an MCP client](#connecting-an-mcp-client)
- [Settings screen](#settings-screen)
- [Abilities reference](#abilities-reference)
  - [Conventions](#conventions)
  - [Content: post type abilities](#content-post-type-abilities)
  - [Content: replace in post content](#content-replace-in-post-content)
  - [Taxonomies](#taxonomies)
  - [Media](#media)
  - [Meta](#meta)
  - [SEOPress](#seopress)
  - [Templates](#templates)
  - [WooCommerce](#woocommerce)
- [Third-party abilities](#third-party-abilities)
- [Security model](#security-model)
- [Filters reference](#filters-reference)
- [Recipes](#recipes)
- [Development](#development)
- [Migrating from bsaweb-mcp-abilities](#migrating-from-bsaweb-mcp-abilities)
- [Contributing](#contributing)
- [License](#license)

## Requirements

| Component | Version | Notes |
| --- | --- | --- |
| WordPress | 6.9 or later | The Abilities API ships with core from 6.9. |
| PHP | 8.1 or later | |
| [MCP Adapter](https://github.com/WordPress/mcp-adapter/releases) | optional | Needed to reach the abilities from an MCP client. Not hosted on WordPress.org. |
| WooCommerce | optional | The WooCommerce abilities are only registered when it runs. |
| SEOPress | optional | The SEOPress abilities are only registered when it runs. `update-post-schemas-seopress` also needs SEOPress Pro. |

MCP is not part of WordPress, whatever the version. Core provides the Abilities API, the catalogue of what an agent may do; the MCP Adapter exposes that catalogue over MCP under `/wp-json/mcp/`.

Without the adapter, the abilities are still registered and usable by any other consumer of the Abilities API. The plugin then shows a warning notice to users who can activate plugins, on the Plugins screen and on its settings screen only, with a link to the adapter releases and, when the adapter's folder is already in `wp-content/plugins/`, the command to activate it. Without the Abilities API (WordPress older than 6.9), nothing can be registered and the notice is an error, shown on every admin screen.

## Installation

**From WordPress.org.** Search for "WP MCP Abilities" under Plugins → Add New, or download the zip from the plugin page and upload it.

**From a GitHub release.** Download the zip attached to a release on the [GitHub repository](https://github.com/Fyrins/wp-mcp-abilities) and upload it under Plugins → Add New → Upload Plugin.

**With Composer (Bedrock and similar setups).**

```bash
composer require fyrins/wp-mcp-abilities
wp plugin activate wp-mcp-abilities
```

The package has the `wordpress-plugin` type, so `composer/installers` places it in your plugins directory. It has no runtime Composer dependency.

**Then install the MCP Adapter.** Download it from its [releases page](https://github.com/WordPress/mcp-adapter/releases), or on a Composer project:

```bash
composer require wordpress/mcp-adapter
wp plugin activate mcp-adapter
```

The adapter is not bundled: it is not hosted on WordPress.org and several plugins may ship it, so it is installed once, as its own plugin.

## Connecting an MCP client

The adapter's default server answers at:

```
https://example.com/wp-json/mcp/mcp-adapter-default-server
```

Its REST namespace is `mcp` and its route `mcp-adapter-default-server`.

**Authentication.** The client acts as a WordPress user. The usual way is an application password: in the WordPress admin, open Users → Profile, create one under "Application Passwords", and send it with HTTP Basic authentication (`username:application-password`, base64-encoded). WordPress only offers application passwords on sites served over HTTPS, or on a local environment.

**Client configuration.** The exact format depends on the client. A typical configuration for a client speaking HTTP looks like this:

```json
{
  "mcpServers": {
    "my-site": {
      "type": "http",
      "url": "https://example.com/wp-json/mcp/mcp-adapter-default-server",
      "headers": {
        "Authorization": "Basic BASE64_OF_USERNAME_COLON_APPLICATION_PASSWORD"
      }
    }
  }
}
```

**How the abilities show up.** The default server exposes the adapter's three generic tools: `mcp-adapter-discover-abilities`, `mcp-adapter-get-ability-info` and `mcp-adapter-execute-ability`. Every ability of this plugin carries `meta.mcp.public`, so the agent finds it through discovery and runs it through `execute-ability`. Third-party abilities exposed from the settings screen are the exception: they become tools of their own (see [Third-party abilities](#third-party-abilities)).

**Which user.** Pick the account deliberately. Every call is checked against that user's capabilities, exactly as in the admin: an Editor account can publish and delete other people's posts, an Author account cannot. A dedicated user with the lowest role that covers the job is the safest choice.

## Settings screen

**Settings → MCP Abilities** (`wp-admin/options-general.php?page=wp-mcp-abilities`), visible to users with `manage_options`.

The screen lists every ability of the plugin that can run on the site, one checkbox each, in sections sorted alphabetically: one per exposed post type (named after its plural label), plus Content, Media, Meta, SEOpress, Taxonomies, Templates and WooCommerce. Abilities whose dependency is missing (WooCommerce, SEOPress, SEOPress Pro) are not listed. Below them come the sections for abilities registered by other plugins, described in [Third-party abilities](#third-party-abilities).

**What unchecking does.** An unchecked ability is not registered at all: it disappears from the Abilities API and from what an MCP client can discover.

**Defaults.** Every ability is enabled by default except the destructive ones, which stay off until an administrator checks them:

- `delete-{post type}` for every post type,
- `delete-media`, `delete-term`, `delete-term-meta`, `delete-template`,
- `delete-product-variation`.

An ability absent from the stored option falls back to its own default, so an ability added by a later version shows up enabled, or disabled if it deletes something. A deletion is the one operation an agent cannot take back, and an update of the plugin must never widen on its own what an agent can reach.

**Storage.** The switches live in the `wpmcpa_enabled` option, an array keyed by ability name. Third-party exposure lives in `wpmcpa_exposed`. Both options are deleted when the plugin is uninstalled.

**Writing the option from code.** The setting is registered on `admin_init`, and from then on its validation applies to every write of the option, not only to the form. It sets to `false` every listed ability the payload does not mention, since an unchecked box is never sent by a form. A partial write such as `update_option( 'wpmcpa_enabled', [ 'wp-mcp-abilities/delete-post' => true ] )` therefore switches everything else off. Read the option, change the key you want, and write the whole array back:

```bash
wp eval '$o = (array) get_option( "wpmcpa_enabled", [] ); $o["wp-mcp-abilities/delete-post"] = true; update_option( "wpmcpa_enabled", $o );'
```

A client that creates test objects and needs to clean up after itself can have `delete-{post type}` enabled for the session this way, then disabled again.

The `wpmcpa_is_enabled` filter can also force the state of an ability from code (see [Filters reference](#filters-reference)).

## Abilities reference

### Conventions

- Every name is prefixed with `wp-mcp-abilities/`, which is also the slug of the ability category the plugin registers.
- **Read capability.** Abilities marked "read" check `edit_posts`, the same baseline as the REST API content endpoints.
- **Two permission checks.** The capability is checked in the ability's `permission_callback`, then again inside `execute()`, against the object actually targeted.
- **Paginated lists** (`list-{plural}`, `list-media`, `list-terms`, `list-templates`) take `per_page` (1 to 100, default 50) and `page` (from 1, default 1), and return `total`, `page` and `per_page` along with the items. Exceptions: `list-taxonomies` takes no parameter and returns a plain array, and the WooCommerce lists use WooCommerce's own collection parameters and return `total` and `total_pages` (see [WooCommerce](#woocommerce)).
- **Annotations.** The plugin's abilities do not declare Abilities API annotations (`readonly`, `destructive`, `idempotent`). Destructive abilities are recognisable by their `delete-` prefix and by being disabled by default.
- **Errors** come back as `WP_Error` with a code and an HTTP status in the error data (for instance `post_not_found` / 404, `insufficient_permissions` / 403).

### Content: post type abilities

Five abilities are generated for each public post type (attachments excepted):

| Ability | What it does | Capability | Default |
| --- | --- | --- | --- |
| `list-{plural}` | Paginated list of published and draft items, most recently modified first | read | enabled |
| `get-{singular}` | One item with its content, excerpt, date and terms | read | enabled |
| `create-{singular}` | Creates an item | the post type's `create_posts` | enabled |
| `update-{singular}` | Updates an item | `edit_post` on that item | enabled |
| `delete-{singular}` | Moves an item to the trash, or deletes it for good with `force` | `delete_post` on that item | disabled |

**Naming.** `{singular}` is the post type slug. `{plural}` is its `rest_base` when it declares one, otherwise the slug followed by `s`. Underscores become hyphens and any character outside `a-z0-9-` is dropped, because the Abilities API only accepts those. So `post` gives `list-posts` and `get-post`, `page` gives `list-pages` and `create-page`, and a `book_review` post type without a `rest_base` gives `list-book-reviews` and `update-book-review`.

**Which post types.** Every post type registered with `public => true`, except `attachment`. The `wpmcpa_post_types` filter narrows or widens the list, and `wpmcpa_post_type_operations` removes individual operations for a post type. If another plugin already registered an ability under the same name, the generated one is skipped rather than overriding it.

**WooCommerce products.** When WooCommerce runs, `product` gives up `create` and `update` to the dedicated [WooCommerce abilities](#woocommerce), which carry the same names (`create-product`, `update-product`). From WooCommerce 10.9, which describes its own catalogue, `list`, `get` and `delete` are given up as well; on older versions they stay generic.

#### `list-{plural}`

- **Input:** `per_page`, `page`.
- **Output:** `items[]` with `id`, `title`, `slug`, `status`, `date_modified`, `link`, `author_id`, `category_ids`; plus `total`, `page`, `per_page`.

#### `get-{singular}`

- **Input:** `post_id` (integer, required).
- **Output:** `id`, `title`, `slug`, `content`, `excerpt`, `status`, `author_id`, `date`, `category_ids`, and `terms`: term IDs keyed by taxonomy, for every assignable taxonomy.

#### `create-{singular}` and `update-{singular}`

Both accept the same writable properties; `create` requires `title`, `update` requires `post_id`.

| Property | Type | Notes |
| --- | --- | --- |
| `post_id` | integer | `update` only. |
| `title` | string | |
| `content` | string | HTML or serialised block markup, stored as sent (kses applies to users without `unfiltered_html`). |
| `excerpt` | string | |
| `slug` | string | Sanitised with `sanitize_title()`; generated from the title when omitted. |
| `status` | `draft` or `publish` | Defaults to `draft` on `create`. `publish` requires the post type's `publish_posts`. |
| `parent_id` | integer | Hierarchical post types only. |
| `category_ids` | integer[] | Post types supporting `category` only. Same as the `category` entry of `terms`. |
| `terms` | object | Term IDs keyed by taxonomy. See below. |
| `author_id` | integer | Requires the post type's `edit_others_posts`. |
| `date` | string | Publication date, `YYYY-MM-DD HH:MM:SS` in site time. |
| `sticky` | boolean | `post` only. Pins (`true`) or unpins (`false`). |

**Output:** `success`, `link`, `status` and `date`, plus `post_id` on `create`. `status` and `date` are read back from the database: WordPress schedules a `publish` carrying a future date as `future`, and the response says so.

**Dating, pinning, classifying.**

- `date` works on creation as on update. It is parsed strictly and must survive a round trip unchanged; anything else, including the `0000-00-00 00:00:00` placeholder, is refused with `invalid_date` instead of producing a post dated year zero.
- Pinning requires `edit_others_posts` or `publish_posts`, as the core REST controller does (`cannot_assign_sticky` otherwise). `sticky_posts` is a site-wide option, so `edit_post` on one's own draft is not enough. Unpinning needs nothing more than the update itself.
- `terms` covers every taxonomy registered for the post type that declares `show_in_rest`, the same rule the [taxonomy abilities](#taxonomies) follow. The schema lists these taxonomies by name, so an agent reading it knows which ones exist. `post_format` falls out on its own, as it does not declare `show_in_rest`. The `wpmcpa_assignable_taxonomies` filter adjusts the list.

```json
{
  "title": "A paper",
  "date": "2026-03-14 09:30:00",
  "terms": {
    "category": [ 12 ],
    "topic": [ 85, 41 ]
  }
}
```

- Each taxonomy sent replaces the terms the post carried in it. A taxonomy left out is untouched, and an empty array clears it.
- Terms are assigned with `wp_set_object_terms()` after the write, so a taxonomy the user may not assign in is refused rather than dropped without a word.
- Sending both `category_ids` and `terms.category` is refused with `ambiguous_categories`: they write the same taxonomy.

**Refusals come before the write.** Unknown or non-assignable taxonomy (`taxonomy_not_assignable`), malformed `terms` (`invalid_terms`), missing `assign_terms` capability (`terms_not_allowed`), unknown term (`term_not_found`), unreadable date, forbidden pin: all of it is checked before `wp_insert_post()` or `wp_update_post()`, so a refusal never leaves a half-created post behind. If assigning terms still fails after the write (a term deleted in between, a third-party filter), the error carries `post_id` and `post_written: true` so the caller can reconcile.

Writes are slashed before reaching `wp_insert_post()` and `wp_update_post()`, so backslashes in block markup (such as `\u002d` escapes in block attributes) survive.

#### `delete-{singular}`

- **Input:** `post_id` (integer, required), `force` (boolean, default `false`: trash; `true`: permanent deletion).
- **Output:** `success`.

### Content: replace in post content

#### `wp-mcp-abilities/replace-in-post-content`

Replaces a literal string inside the content of a post of any type, without the caller resending the whole content. The `update-*` abilities take the full content, so changing three words in a long page means reading it, reproducing it and sending it back, which costs a lot and risks a silent transcription slip.

- **Input:**
  - `post_id` (integer, required);
  - `search` (string, required, at least 1 character): the literal string to look for;
  - `replace` (string, required): what goes in its place; an empty string deletes the match;
  - `expected_occurrences` (integer, 0 or more): how many matches the caller expects; if the count differs, nothing is written and the error `unexpected_occurrences` (409) reports the count found;
  - `dry_run` (boolean, default `false`): counts and measures without writing.
- **Output:** `success`, `post_id`, `occurrences`, `replaced` (true when the content was actually written), `dry_run`, `length_before`, `length_after` (read back from the database, or projected on a dry run), `content_intact`.
- **Capability:** `edit_post` on the target post. **Default:** enabled.

The search is a literal string, never a regular expression: block markup is full of characters a pattern would interpret. Literal also means untouched. `search` and `replace` are used byte for byte, tags, attributes, HTML comments, newlines and runs of spaces included, so a caller can anchor on `<h1 class="wp-block-heading">` or on the boundary between two blocks. What the user may write is decided by the `edit_post` check and by kses on the way into the database, not by altering the needle.

`content_intact` is `false` when what was stored differs from what was submitted, which points to `wp_filter_post_kses()` having stripped markup because the user lacks `unfiltered_html`.

A missing `search` gives `missing_search`, an empty one `empty_search`.

### Taxonomies

The scope is every taxonomy declaring `show_in_rest`, the same rule as the taxonomies assignable through `terms`: whatever a client can assign, it can also list, read and edit. Internal taxonomies that declare `show_in_rest`, such as `nav_menu` and `wp_pattern_category`, stay reachable on purpose: they carry their own capabilities (`edit_theme_options` for `nav_menu`), `delete-term` is off by default, and each ability can be switched off. Use the `wpmcpa_taxonomies` filter to narrow the scope. A taxonomy outside it is refused with `taxonomy_not_allowed`.

Terms are returned as `id`, `name`, `slug`, `description`, `parent`, `count`, `taxonomy`.

#### `wp-mcp-abilities/list-taxonomies`

- **Input:** none.
- **Output:** an array of `{ name, label, hierarchical, object_types[] }`.
- **Capability:** read. **Default:** enabled.

#### `wp-mcp-abilities/list-terms`

- **Input:** `taxonomy` (string, required), `search` (string), `parent` (integer: children of this term), `hide_empty` (boolean, default `false`), `per_page`, `page`.
- **Output:** `terms[]`, `total`, `page`, `per_page`.
- **Capability:** read. **Default:** enabled.

#### `wp-mcp-abilities/get-term`

- **Input:** `term_id` (integer, required), `taxonomy` (string, optional when the ID is unambiguous).
- **Output:** the term.
- **Capability:** read. **Default:** enabled.

#### `wp-mcp-abilities/create-term`

- **Input:** `taxonomy` (string, required), `name` (string, required), `slug` (generated from the name when omitted), `description`, `parent_id` (hierarchical taxonomies).
- **Output:** `success`, `term`.
- **Capability:** the taxonomy's `manage_terms`. **Default:** enabled.

#### `wp-mcp-abilities/update-term`

- **Input:** `term_id` (integer, required), `taxonomy`, `name`, `slug`, `description`, `parent_id` (`0` moves the term to the root).
- **Output:** `success`, `term`.
- **Capability:** `edit_term` on that term. **Default:** enabled.

#### `wp-mcp-abilities/delete-term`

- **Input:** `term_id` (integer, required), `taxonomy` (optional when the ID is unambiguous).
- **Output:** `success`. The deletion is permanent.
- **Capability:** `delete_term` on that term. **Default:** disabled.

### Media

Media are returned as `id`, `title`, `filename`, `url`, `mime_type`, `alt_text`, `caption`.

#### `wp-mcp-abilities/list-media`

- **Input:** `mime_type` (string, such as `image` or `image/png`), `search` (string), `per_page`, `page`.
- **Output:** `items[]`, `total`, `page`, `per_page`.
- **Capability:** read. **Default:** enabled.

#### `wp-mcp-abilities/upload-media`

Downloads a file from a URL and adds it to the media library.

- **Input:** `url` (string, required, `http` or `https` only), `filename` (string, inferred from the URL when omitted), `alt_text` (string).
- **Output:** `success`, `media`.
- **Capability:** `upload_files`. **Default:** enabled.

The download goes through core's `download_url()`, which refuses unsafe URLs such as internal addresses. Files larger than 10 MB are refused with `file_too_large` (adjust with `wpmcpa_upload_media_max_bytes`). The file type is validated by `media_handle_sideload()`, as for any upload.

#### `wp-mcp-abilities/update-media`

- **Input:** `attachment_id` (integer, required), `title`, `alt_text`, `caption`.
- **Output:** `success`, `media`.
- **Capability:** `edit_post` on the attachment. **Default:** enabled.

#### `wp-mcp-abilities/set-featured-image`

- **Input:** `post_id` (integer, required), `attachment_id` (integer, required).
- **Output:** `success`, `post_id`, `attachment_id`.
- **Capability:** `edit_post` on the post. **Default:** enabled.

#### `wp-mcp-abilities/delete-media`

- **Input:** `attachment_id` (integer, required).
- **Output:** `success`. The attachment and its files are deleted permanently.
- **Capability:** `delete_post` on the attachment. **Default:** disabled.

### Meta

Protected meta keys (prefixed with `_`, or declared protected with `is_protected_meta`) are never read, written or deleted unless they are on an allow-list, empty by default and filled through `wpmcpa_allowed_meta_keys`. A refused key gives `meta_key_not_allowed` (403).

`meta_value` accepts a string, a number, a boolean, or an array of those; objects are refused with `invalid_meta_value`. Strings are sanitised with `sanitize_text_field()`.

#### `wp-mcp-abilities/get-post-meta`

- **Input:** `post_id` (integer, required), `meta_key` (string; omit it to get every readable meta of the post).
- **Output:** `success`, `post_id`, and either `meta_key` with `meta_value`, or `meta` (every readable key, single values unwrapped).
- **Capability:** read. **Default:** enabled.

#### `wp-mcp-abilities/update-post-meta`

- **Input:** `post_id` (integer, required), `meta_key` (string, required), `meta_value` (required).
- **Output:** `success`, `post_id`, `meta_key`, `meta_value`.
- **Capability:** `edit_post` on the post. **Default:** enabled.

#### `wp-mcp-abilities/get-term-meta`

- **Input:** `term_id` (integer, required), `taxonomy` (disambiguates the lookup), `meta_key` (omit it to get every readable meta of the term).
- **Output:** `success`, `term_id`, and either `meta_key` with `meta_value`, or `meta`.
- **Capability:** read. **Default:** enabled.

#### `wp-mcp-abilities/update-term-meta`

- **Input:** `term_id` (integer, required), `taxonomy`, `meta_key` (string, required), `meta_value` (required).
- **Output:** `success`, `term_id`, `meta_key`, `meta_value`.
- **Capability:** `edit_term` on the term. **Default:** enabled.

#### `wp-mcp-abilities/delete-term-meta`

- **Input:** `term_id` (integer, required), `taxonomy`, `meta_key` (string, required).
- **Output:** `success`, `term_id`, `meta_key`.
- **Capability:** `edit_term` on the term. **Default:** disabled.

### SEOPress

Registered only when SEOPress is active (`SEOPRESS_VERSION` defined); `update-post-schemas-seopress` also needs SEOPress Pro. Without them the abilities do not exist, rather than reporting a success while writing orphan meta.

Fields shared by posts and terms (read and write):

| Field | Type | SEOPress meta |
| --- | --- | --- |
| `meta_title` | string | `_seopress_titles_title` |
| `meta_description` | string | `_seopress_titles_desc` |
| `canonical_url` | string (URL) | `_seopress_robots_canonical` |
| `noindex` | boolean | `_seopress_robots_index` |
| `nofollow` | boolean | `_seopress_robots_follow` |
| `og_title`, `og_description`, `og_image` | string | `_seopress_social_fb_*` |
| `twitter_title`, `twitter_description`, `twitter_image` | string | `_seopress_social_twitter_*` |

Fields specific to posts:

| Field | Type | SEOPress meta |
| --- | --- | --- |
| `focus_keyword` | string, comma-separated keywords | `_seopress_analysis_target_kw` |
| `primary_category` | string: a category ID, or `none` | `_seopress_robots_primary_cat` |
| `breadcrumb_title` | string | `_seopress_robots_breadcrumbs` |
| `nosnippet` | boolean | `_seopress_robots_snippet` |
| `noimageindex` | boolean | `_seopress_robots_imageindex` |
| `redirect_enabled` | boolean | `_seopress_redirections_enabled` |
| `redirect_type` | `301`, `302` or `307` | `_seopress_redirections_type` |
| `redirect_url` | string (URL) | `_seopress_redirections_value` |

Booleans are stored as SEOPress expects them (`yes` or empty). Empty values are read back as `null`.

#### `wp-mcp-abilities/get-post-seopress`

- **Input:** `post_id` (integer, required).
- **Output:** every shared and post-specific field.
- **Capability:** read. **Default:** enabled.

#### `wp-mcp-abilities/update-post-seopress`

- **Input:** `post_id` (integer, required) and any shared or post-specific field. Only the fields sent are written.
- **Output:** `success`.
- **Capability:** `edit_post` on the post. **Default:** enabled.

#### `wp-mcp-abilities/update-post-schemas-seopress`

Replaces the SEOPress Pro manual JSON-LD schemas of a post (`_seopress_pro_schemas_manual`).

- **Input:** `post_id` (integer, required), `schemas` (string[], required): each entry is a raw JSON object or a full `<script type="application/ld+json">` tag. The list replaces every manual schema; an empty array clears them.
- **Output:** `success`, `count`.
- **Capability:** `edit_post` on the post. **Default:** enabled.

Each entry is validated as JSON (`invalid_json_ld` otherwise) and re-encoded with `JSON_HEX_TAG` before storage, so a `</script>` inside a string cannot close the tag on the front end.

#### `wp-mcp-abilities/get-term-seopress`

- **Input:** `term_id` (integer, required), `taxonomy` (optional when the ID is unambiguous).
- **Output:** the shared fields.
- **Capability:** read. **Default:** enabled.

#### `wp-mcp-abilities/update-term-seopress`

- **Input:** `term_id` (integer, required), `taxonomy`, and any shared field.
- **Output:** `success`.
- **Capability:** `edit_term` on the term. **Default:** enabled.

### Templates

Block templates (`wp_template`) and template parts (`wp_template_part`). Every ability takes `type`, one of these two values, defaulting to `wp_template`. Reads go through `get_block_templates()` and `get_block_template()`, so templates provided by theme files are visible alongside those stored in the database.

A template is identified either by `id`, formatted `{theme}//{slug}`, or by `slug`, which is completed with the active theme. Slugs may only contain letters, digits, `_`, `%` and `-`.

Templates are returned as `id`, `slug`, `theme`, `type`, `title`, `description`, `content`, `source`, `origin`, `has_theme_file`, `wp_id`, `is_custom` (templates only), `area` (template parts only), `status`.

#### `wp-mcp-abilities/list-templates`

- **Input:** `type`, `area` (template parts only), `per_page`, `page`.
- **Output:** `items[]`, `total`, `page`, `per_page`.
- **Capability:** read. **Default:** enabled.

#### `wp-mcp-abilities/get-template`

- **Input:** `type`, `id` or `slug`.
- **Output:** the template.
- **Capability:** read. **Default:** enabled.

#### `wp-mcp-abilities/create-template`

Creates a template or template part tagged with the active theme.

- **Input:** `slug` (required), `type`, `title`, `description`, `content` (serialised block markup), `area` (template parts only; `uncategorized`, `header`, `footer`, `navigation-overlay`; invalid values fall back to `uncategorized`).
- **Output:** `success`, `content_filtered`, `template`.
- **Capability:** `edit_theme_options`. **Default:** enabled.

#### `wp-mcp-abilities/update-template`

- **Input:** `type`, `id` or `slug`, `title`, `description`, `content`, `area`, `revert` (boolean, default `false`).
- **Output:** `success`, `reverted`, `content_filtered`, `template`.
- **Capability:** `edit_theme_options`. **Default:** enabled.

Three cases:

- the template already exists in the database: it is updated;
- it only exists as a theme file: a database copy is created, which from then on overrides the file;
- with `revert: true`: the database copy is removed and the theme file takes over again.

`content_filtered` is `true` when WordPress filtered the submitted markup, which happens when the user lacks `unfiltered_html`.

#### `wp-mcp-abilities/delete-template`

- **Input:** `type`, `id` or `slug`, `force` (boolean, default `false`: trash; `true`: permanent deletion).
- **Output:** `success`.
- **Capability:** `edit_theme_options`. **Default:** disabled.

Only customised templates (stored in the database) can be deleted; templates that come from theme files are refused with `invalid_template`.

### WooCommerce

Registered only when WooCommerce runs. Without it, none of them exists.

WooCommerce 10.9 and later register their own product abilities (`woocommerce/products-query`, `product-delete` and neighbours). This plugin does not duplicate them. It covers what they leave out: variations, global attributes, and writing a variable product.

These abilities write nothing themselves: they hand the request to the WooCommerce REST controllers (`/wc/v3`). Their input schemas are derived at runtime from those controllers' arguments, so a field added by a WooCommerce release appears without a change here. Business rules and permission checks are those of the REST API.

| Ability | Route | What it does | Default |
| --- | --- | --- | --- |
| `wp-mcp-abilities/create-product` | `POST /wc/v3/products` | Creates a product, `variable` included | enabled |
| `wp-mcp-abilities/update-product` | `PUT /wc/v3/products/{id}` | Updates a product; only the fields sent are written, and `type` may turn a simple product into a variable one | enabled |
| `wp-mcp-abilities/list-product-variations` | `GET /wc/v3/products/{id}/variations` | Lists the variations of a variable product | enabled |
| `wp-mcp-abilities/get-product-variation` | `GET /wc/v3/products/{id}/variations/{id}` | Reads one variation | enabled |
| `wp-mcp-abilities/create-product-variation` | `POST /wc/v3/products/{id}/variations` | Creates a variation | enabled |
| `wp-mcp-abilities/update-product-variation` | `PUT /wc/v3/products/{id}/variations/{id}` | Updates a variation; sending `attributes` replaces the current pairs | enabled |
| `wp-mcp-abilities/delete-product-variation` | `DELETE /wc/v3/products/{id}/variations/{id}` | Deletes a variation for good (`force: true`; variations have no trash) | disabled |
| `wp-mcp-abilities/list-product-attributes` | `GET /wc/v3/products/attributes` | Lists the global attributes | enabled |
| `wp-mcp-abilities/create-product-attribute` | `POST /wc/v3/products/attributes` | Creates a global attribute | enabled |

**Inputs.**

- `create-product`: the controller's creation fields, `name` required.
- `update-product`: `product_id` (required) plus the controller's update fields.
- `list-product-variations`: `product_id` (required) plus the controller's collection parameters (`page`, `per_page`, filters).
- `get-product-variation`, `delete-product-variation`: `product_id` and `variation_id` (required).
- `create-product-variation`: `product_id` (required) plus the controller's creation fields. Each `attributes` entry pairs an attribute of the parent with one of its values; an empty value means "any". The parent must be `variable` and carry attributes marked for variations.
- `update-product-variation`: `product_id` and `variation_id` (required) plus the controller's update fields.
- `list-product-attributes`: nothing besides `_fields`.
- `create-product-attribute`: the controller's creation fields, `name` required.

Every ability except `delete-product-variation` accepts `_fields`, a comma-separated list of the fields to return (for instance `id,name,sku,price,stock_quantity`). A product has more than seventy properties, and a page of results pays for all of them when only three are needed.

**Outputs.** The REST API response, minus its `_links`. The output schema is deliberately open: WooCommerce describes its fields for input, not output (`stock_quantity` is an integer that answers `null` when stock is not managed), and enforcing those descriptions would reject ordinary products. Lists are wrapped: `variations[]` or `attributes[]`, plus `total` and `total_pages` when WooCommerce sends its pagination headers. Both keys are absent, rather than guessed, when it does not.

**Capabilities.** Those of the WooCommerce routes. Their verdict then goes through `wpmcpa_check_permission`, with `rest_route` as the capability and the route as the context. An invalid payload comes back as WooCommerce's validation error, not as a permission refusal.

**Attributes created in the same request.** WooCommerce registers its `pa_*` taxonomies on `init`, from the database. A global attribute created by `create-product-attribute` is registered on the spot, so values assigned to it later in the same request are not dropped.

## Third-party abilities

The settings screen also lists the abilities other plugins registered, grouped by namespace under "Other plugin: {namespace}", with one checkbox each. What the checkbox does depends on how the ability declares itself.

**Abilities open to MCP** (they carry `meta.mcp.public`): the checkbox **removes** them. They stay checked by default, since unchecking them on update would break integrations already in place.

- Removal uses `wp_unregister_ability()`, the only lever the Abilities API offers.
- It only happens on the routes served by the MCP Adapter, under `/wp-json/mcp/`, when the request is dispatched (`rest_pre_dispatch`). The rest of the site is untouched: the plugin that provides the ability keeps using it, including from its own admin screens over REST. Removing it everywhere would also make the setting irreversible, since an ability missing from the registry would be missing from the screen, checkbox included.
- A site serving its MCP server elsewhere than under `/wp-json/mcp/` adds its route prefix with `wpmcpa_mcp_route_prefixes`, otherwise the switches have no effect.
- An ability declaring `meta.mcp.type` as `resource` or `prompt` stays listed by the adapter's default server once unchecked, because the server freezes those two lists when it is built (measured on adapter 0.5). Running it still fails, as the registry is read at call time. Tools are not affected.

**Abilities closed to MCP** (no `meta.mcp.public`, as with SEOPress's abilities, written for the core REST API): the checkbox **exposes** them. Checking one declares it to the adapter's default server as a tool, through the adapter's `mcp_adapter_default_server_config` filter, without touching the registry or the ability's metadata.

- These boxes are unchecked by default and stay so across updates.
- Exposing grants no right: the ability's own permission check decides, as anywhere else. What changes is that an agent can reach it.
- An exposed ability becomes a tool of the server: it appears in `tools/list` and answers `tools/call` under a name derived from its own (`seopress/get-post-title-description` becomes `seopress-get-post-title-description`). `mcp-adapter/execute-ability` still refuses it, since it requires the flag its plugin did not set. Clients use the tool list.
- Abilities that declare themselves destructive (`annotations.destructive`) are flagged on the screen.
- If the site switches off the default server (`mcp_adapter_create_default_server` returns false), the section says so: the boxes would have no effect.
- A removal decided by the first kind of checkbox wins over an exposure.

The exposure state is stored in `wpmcpa_exposed`, separate from the switches, since the two settings have opposite defaults.

**Never listed.** Core abilities (`core/*`) and the adapter's own (`mcp-adapter/*`): removing `mcp-adapter/execute-ability` would cut the branch the agent sits on. This plugin's abilities are not listed either; they are recognised by the `meta.wpmcpa.plugin` mark they carry, not by their prefix, which another plugin could share.

## Security model

- **Every call runs as a logged-in user** and respects that user's capabilities. The check runs in the `permission_callback`, then again in `execute()`.
- **Object capabilities, not global ones.** Writes check the meta capability bound to the target: `edit_post`, `delete_post`, `edit_term`, `delete_term`. A Contributor cannot edit or delete another author's content.
- **Editorial workflow.** Publishing requires the post type's `publish_posts`; changing the author requires `edit_others_posts`; pinning requires `edit_others_posts` or `publish_posts`; assigning terms requires each taxonomy's `assign_terms`.
- **Reads** require `edit_posts`.
- **Templates.** Creating, updating and deleting require `edit_theme_options`, as in the Site Editor. Listing and reading require `edit_posts`.
- **Meta.** Protected keys are out of reach unless allow-listed (`wpmcpa_allowed_meta_keys`, empty by default). Values are limited to scalars and arrays of scalars, and strings are sanitised.
- **Markup.** Content and template writes go through kses for users without `unfiltered_html`; `content_intact` and `content_filtered` report when markup was stripped. JSON-LD schemas are re-encoded with `JSON_HEX_TAG`.
- **Uploads.** `http` and `https` URLs only, downloaded by core's `download_url()`, 10 MB by default, file type validated by `media_handle_sideload()`.
- **Taxonomies.** Only those declaring `show_in_rest`.
- **Destructive abilities** are disabled until an administrator enables them. The settings screen requires `manage_options`.
- **Integrator override.** Every capability decision goes through `wpmcpa_check_permission`.
- **No outgoing calls of its own.** The plugin sends nothing to any third party, has no telemetry and loads no external asset. The only HTTP request it can make is the download `upload-media` performs, to the URL the caller supplies.

Allow a protected meta key:

```php
add_filter(
    'wpmcpa_allowed_meta_keys',
    function ( array $keys, string $objectType ): array {
        if ( 'post' === $objectType ) {
            $keys[] = '_my_internal_field';
        }

        return $keys;
    },
    10,
    2
);
```

## Filters reference

All filters are applied with `apply_filters()`; the parameters below are those passed, in order.

### Registration and availability

| Filter | Parameters | Purpose |
| --- | --- | --- |
| `wpmcpa_abilities` | `array<string, AbilityInterface> $abilities` | The static abilities about to be registered, keyed by name. Add, replace or remove entries. |
| `wpmcpa_is_enabled` | `bool $enabled`, `string $ability` | Forces whether an ability of the plugin, or a third-party one, is enabled. |
| `wpmcpa_is_exposed` | `bool $exposed`, `string $ability` | Forces whether a third-party ability closed to MCP is exposed. |
| `wpmcpa_post_types` | `WP_Post_Type[] $postTypes` | Post types that get generated abilities. |
| `wpmcpa_post_type_operations` | `string[] $operations`, `WP_Post_Type $postType` | Operations (`list`, `get`, `create`, `update`, `delete`) generated for a post type. |
| `wpmcpa_taxonomies` | `array<string, WP_Taxonomy> $taxonomies` | Taxonomies reachable through the taxonomy abilities. |
| `wpmcpa_assignable_taxonomies` | `array<string, WP_Taxonomy> $taxonomies`, `WP_Post_Type $postType` | Taxonomies the post type abilities may assign terms in. |
| `wpmcpa_mcp_route_prefixes` | `string[] $prefixes` | REST route prefixes treated as MCP traffic for third-party switches. Default `[ '/mcp/' ]`. |

`wpmcpa_abilities` runs on `wp_abilities_api_init`. The plugin itself hooks it at priority 20 to drop abilities that are switched off or miss a dependency, so a callback at the default priority sees every static ability. Entries must implement `WpMcpAbilities\Contracts\AbilityInterface`; anything else is ignored. The generated post type abilities do not go through this filter (use the two post type filters). Abilities added here are registered but not listed on the settings screen.

### Permissions and meta

| Filter | Parameters | Purpose |
| --- | --- | --- |
| `wpmcpa_check_permission` | `bool $allowed`, `string $capability`, `mixed $context` | Overrides any capability decision. `$context` is the post, term, post type or taxonomy slug, or REST route the check was made against, when any. |
| `wpmcpa_allowed_meta_keys` | `string[] $keys`, `string $objectType` | Protected meta keys the meta abilities may touch. `$objectType` is `post` or `term`. Empty by default. |

### Content

| Filter | Parameters | Purpose |
| --- | --- | --- |
| `wpmcpa_list_posts_query_args` | `array $args`, `array $input` | `WP_Query` arguments of every `list-{plural}` ability. |
| `wpmcpa_list_posts_result` | `array $result`, `WP_Post[] $posts` | Payload of `list-{plural}`. |
| `wpmcpa_get_post_result` | `array $result`, `WP_Post $post` | Payload of `get-{singular}`. |
| `wpmcpa_create_post_data` | `array $postData`, `array $input` | `wp_insert_post()` arguments of `create-{singular}`, before slashing. |
| `wpmcpa_update_post_data` | `array $updateData`, `WP_Post $post`, `array $input` | `wp_update_post()` arguments of `update-{singular}`, before slashing. |
| `wpmcpa_replaced_post_content` | `string $after`, `WP_Post $post`, `string $search` | Content about to be stored by `replace-in-post-content`. Not applied on dry runs. |

The post type filters are shared by every post type; check `$args['post_type']`, `$post->post_type` or `$postData['post_type']` to target one.

### Media

| Filter | Parameters | Purpose |
| --- | --- | --- |
| `wpmcpa_list_media_query_args` | `array $args`, `array $input` | `WP_Query` arguments of `list-media`. |
| `wpmcpa_list_media_result` | `array $result`, `WP_Post[] $items` | Payload of `list-media`. |
| `wpmcpa_update_media_args` | `array $updateArgs`, `WP_Post $attachment`, `array $input` | `wp_update_post()` arguments of `update-media`. |
| `wpmcpa_upload_media_max_bytes` | `int $maxBytes` | Largest accepted download for `upload-media`. Default 10 MB. |
| `wpmcpa_upload_media_file` | `array $fileArray`, `array $input` | File array (`name`, `tmp_name`) passed to `media_handle_sideload()`. |

### Taxonomies

| Filter | Parameters | Purpose |
| --- | --- | --- |
| `wpmcpa_list_taxonomies_result` | `array $result` | Payload of `list-taxonomies`. |
| `wpmcpa_list_terms_query_args` | `array $args`, `array $input` | `get_terms()` arguments of `list-terms`. |
| `wpmcpa_list_terms_result` | `array $result`, `WP_Term[] $terms` | Payload of `list-terms`. |
| `wpmcpa_get_term_result` | `array $result`, `WP_Term $term` | Payload of `get-term`. |
| `wpmcpa_create_term_args` | `array $args`, `string $taxonomy`, `array $input` | `wp_insert_term()` arguments of `create-term`. |
| `wpmcpa_update_term_args` | `array $args`, `WP_Term $term`, `array $input` | `wp_update_term()` arguments of `update-term`. |

### SEOPress

| Filter | Parameters | Purpose |
| --- | --- | --- |
| `wpmcpa_get_post_seo_result` | `array $result`, `WP_Post $post` | Payload of `get-post-seopress`. |
| `wpmcpa_update_seo_data` | `array $seoData`, `WP_Post $post`, `array $input` | Values about to be written by `update-post-seopress`, keyed by input field. |
| `wpmcpa_get_term_seo_result` | `array $result`, `WP_Term $term` | Payload of `get-term-seopress`. |
| `wpmcpa_update_term_seo_data` | `array $seoData`, `WP_Term $term`, `array $input` | Values about to be written by `update-term-seopress`, keyed by input field. |

### Templates

| Filter | Parameters | Purpose |
| --- | --- | --- |
| `wpmcpa_list_templates_query_args` | `array $query`, `string $type`, `array $input` | `get_block_templates()` arguments of `list-templates`. |
| `wpmcpa_list_templates_result` | `array $result`, `WP_Block_Template[] $templates` | Payload of `list-templates`. |
| `wpmcpa_get_template_result` | `array $result`, `WP_Block_Template $template` | Payload of `get-template`. |
| `wpmcpa_create_template_args` | `array $prepared`, `string $type`, `array $input` | `wp_insert_post()` arguments of `create-template`, before slashing. |
| `wpmcpa_update_template_args` | `array $prepared`, `WP_Block_Template $template`, `array $input` | `wp_update_post()` or `wp_insert_post()` arguments of `update-template`, before slashing. |

### Examples

Keep a post type out of reach:

```php
add_filter( 'wpmcpa_post_types', function ( array $postTypes ): array {
    unset( $postTypes['page'] );

    return $postTypes;
} );
```

Expose a post type's reads but not its writes:

```php
add_filter( 'wpmcpa_post_type_operations', function ( array $operations, WP_Post_Type $postType ): array {
    return 'book' === $postType->name ? [ 'list', 'get' ] : $operations;
}, 10, 2 );
```

Keep deletions off on production, whatever the settings screen says:

```php
add_filter( 'wpmcpa_is_enabled', function ( bool $enabled, string $ability ): bool {
    if ( 'production' === wp_get_environment_type() && str_contains( $ability, '/delete-' ) ) {
        return false;
    }

    return $enabled;
}, 10, 2 );
```

Only let administrators delete posts and terms through an ability:

```php
add_filter( 'wpmcpa_check_permission', function ( bool $allowed, string $capability ): bool {
    if ( in_array( $capability, [ 'delete_post', 'delete_term' ], true ) && ! current_user_can( 'manage_options' ) ) {
        return false;
    }

    return $allowed;
}, 10, 2 );
```

Serve the third-party switches on a custom MCP server route:

```php
add_filter( 'wpmcpa_mcp_route_prefixes', function ( array $prefixes ): array {
    $prefixes[] = '/my-agents/';

    return $prefixes;
} );
```

Accept larger uploads:

```php
add_filter( 'wpmcpa_upload_media_max_bytes', fn (): int => 25 * MB_IN_BYTES );
```

## Recipes

The plugin ships no unit test suite. The files in [`docs/recipes/`](docs/recipes/) are acceptance scripts for the most intricate families; run them with WP-CLI on a site where the relevant plugins are active, after any change to that family. They live in the repository only and are not part of the distributed package.

- [`woocommerce-catalogue.php`](docs/recipes/woocommerce-catalogue.php): `wp eval-file docs/recipes/woocommerce-catalogue.php --user=1` on a site running WooCommerce. Creates a global attribute and a variable product with six variations through the abilities, checks the parent's price range, then deletes everything it created. It covers logic, schemas and permissions, not the MCP transport.
- [`third-party-switches.php`](docs/recipes/third-party-switches.php): `wp eval-file docs/recipes/third-party-switches.php` on a site where another plugin registers abilities. Switches off a third-party ability, then dispatches two REST requests to check that it is removed on MCP routes only. The option is restored afterwards.
- [`third-party-exposure.php`](docs/recipes/third-party-exposure.php): `wp eval-file docs/recipes/third-party-exposure.php` on a site running a plugin whose abilities are closed to MCP (SEOPress 10, for instance). Checks a closed ability and verifies what the plugin declares to the default server, by applying the adapter's own configuration filter. Checking that an agent actually sees the tool still takes an MCP client.

## Development

### Setup

```bash
git clone https://github.com/Fyrins/wp-mcp-abilities.git
cd wp-mcp-abilities
composer install
composer lint      # PHP_CodeSniffer with phpcs.xml.dist
composer format    # PHP Code Beautifier and Fixer
```

`phpcs.xml.dist` applies the WordPress Coding Standards, PHPCompatibilityWP for PHP 8.1 and later, the minimum WordPress version (6.9), and the text domain and prefix checks (`wpmcpa`, `WpMcpAbilities`).

### Local WordPress and smoke tests

`bin/smoke.sh` syncs the publishable package into a local WordPress site, runs every script of `tests/smoke/` with `wp eval-file`, and fails if a script fails or if `wp-content/debug.log` is not empty afterwards.

```bash
bin/smoke.sh                 # every smoke script
bin/smoke.sh settings        # one script
```

It expects a site at `$WPMCPA_TESTBED` (default `~/Sites/wp-mcp-abilities-testbed`) with:

- WordPress 6.9 or later, installed with WP-CLI (`wp core download`, `wp config create`, `wp core install`), with `WP_DEBUG` and `WP_DEBUG_LOG` on so errors land in `wp-content/debug.log`;
- WP-CLI reachable as `ddev wp` (the script runs `ddev wp eval-file`; the site is a [DDEV](https://ddev.com/) project), and as `wp` inside that environment;
- an executable `sync-plugin.sh` at the site root that copies the package into the plugins directory. For example:

```sh
#!/bin/sh
set -e
SRC="$HOME/code/wp-mcp-abilities"
DEST="$(dirname "$0")/wp-content/plugins/wp-mcp-abilities"
mkdir -p "$DEST"
rsync -a --delete --exclude-from="$SRC/.distignore" "$SRC/" "$DEST/"
```

- the plugin active, and neither the MCP Adapter, WooCommerce nor SEOPress installed: the smoke scripts check the behaviour without them.

The scripts cover registration on the plugin's own wiring, the optional adapter (warning notice and the screens it shows on, abilities registered anyway) and the settings switches.

### Translations

The plugin relies on WordPress's just-in-time translation loading (text domain `wp-mcp-abilities`, `languages/`). To refresh the catalogue after changing strings:

```bash
wp i18n make-pot . languages/wp-mcp-abilities.pot --exclude=vendor,tests,docs
wp i18n update-po languages/wp-mcp-abilities.pot languages/
wp i18n make-mo languages/
```

### Code structure

| Path | Role |
| --- | --- |
| `wp-mcp-abilities.php` | Plugin header, constants (`WPMCPA_VERSION`, `WPMCPA_FILE`, `WPMCPA_DIR`), autoloader, `Plugin::boot()`. |
| `includes/Autoloader.php` | Maps `WpMcpAbilities\Foo\Bar` to `includes/Foo/Bar.php`. |
| `includes/Plugin.php` | Explicit wiring: builds every service and hook once. `Plugin::ABILITIES` lists the static ability classes. |
| `includes/Registry/` | `AbilityRegistry` holds the category and the static abilities, and registers them on `wp_abilities_api_categories_init` and `wp_abilities_api_init`. |
| `includes/Contracts/` | `AbilityInterface`, `AbilityCategoryInterface`, `HookInterface`. |
| `includes/AbilityCategories/` | The `wp-mcp-abilities` ability category. |
| `includes/Abilities/` | One class per static ability, by family (`Content`, `Media`, `Meta`, `Seopress`, `Taxonomy`, `Template`, `WooCommerce`), with the traits they share. |
| `includes/Hooks/` | WordPress integration: availability filtering, post type abilities, third-party switches and exposure, requirements notice, WooCommerce product types. `Hooks/Admin/` holds the settings screen. |
| `includes/Services/` | Logic without hooks: settings reader, catalogue of the plugin's abilities, catalogue of third-party abilities. |
| `includes/Support/` | `AbstractAbility`, `PostTypeAbility` (the five generated operations), `PostTypeTaxonomies` (term rules of a post type), `Capabilities`, `Input`, `Requirements`, `RequestCache`. |
| `uninstall.php` | Deletes the two options. |

### Adding an ability

1. Create a class under `includes/Abilities/<Family>/` extending `WpMcpAbilities\Support\AbstractAbility`. It must be instantiable without arguments. Implement `getName()` (use `self::qualify( 'my-slug' )`), `getLabel()`, `getDescription()`, `getGroup()`, `getInputSchema()`, `getOutputSchema()`, `checkPermission()` and `execute()`. Override `isEnabledByDefault()` to return `false` if it deletes anything, and `isAvailable()` if it depends on another plugin.
2. Add the class to `Plugin::ABILITIES` in `includes/Plugin.php`.
3. Check capabilities against the targeted object with `Support\Capabilities`, in `checkPermission()` and again in `execute()`, and read input with `Support\Input`.
4. Document it in this README, add a changelog entry, refresh the translation catalogue, run `composer lint` and `bin/smoke.sh`.

```php
namespace WpMcpAbilities\Abilities\Content;

use WpMcpAbilities\Support\AbstractAbility;
use WpMcpAbilities\Support\Capabilities;
use WpMcpAbilities\Support\Input;

class CountWordsAbility extends AbstractAbility {
    public function getName(): string {
        return self::qualify( 'count-words' );
    }

    public function getLabel(): string {
        return __( 'Count words', 'wp-mcp-abilities' );
    }

    public function getDescription(): string {
        return __( 'Counts the words of a post.', 'wp-mcp-abilities' );
    }

    public function getGroup(): string {
        return __( 'Content', 'wp-mcp-abilities' );
    }

    public function getInputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [ 'post_id' => [ 'type' => 'integer' ] ],
            'required'   => [ 'post_id' ],
        ];
    }

    public function getOutputSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [ 'words' => [ 'type' => 'integer' ] ],
        ];
    }

    public function checkPermission( mixed $input = null ): bool {
        $post = $this->resolvePost( Input::int( Input::normalize( $input ), 'post_id' ) );

        return ! is_wp_error( $post ) && Capabilities::canEditPost( $post );
    }

    public function execute( mixed $input = null ): array|\WP_Error {
        $post = $this->resolvePost( Input::int( Input::normalize( $input ), 'post_id' ) );

        if ( is_wp_error( $post ) ) {
            return $post;
        }

        if ( ! Capabilities::canEditPost( $post ) ) {
            return $this->forbidden();
        }

        return [ 'words' => str_word_count( wp_strip_all_tags( $post->post_content ) ) ];
    }
}
```

`getProperties()`, inherited from `AbstractAbility`, sets the category, wires the callbacks, and adds `meta.mcp.public` and the `meta.wpmcpa.plugin` mark.

### Checklist when upgrading the MCP Adapter

The adapter is still in `0.x`, and the plugin relies on signals that are not documented as public API. After every adapter upgrade, check that they still hold:

- **`WP_MCP_VERSION` and `mcp_adapter_init`.** `Requirements::hasMcpAdapter()` treats the adapter as running when the constant is defined or the action has fired. If a release renames them, the plugin warns that the adapter is missing while it runs.
- **`meta.mcp.public`.** Every ability sets it in `AbstractAbility::getProperties()`. The adapter reads it to decide what it exposes and treats a missing value as `false`: an ability without it is registered but invisible to discovery, and recent versions also refuse to run it.
- **The default server.** Its route under the `mcp` REST namespace (`/mcp/mcp-adapter-default-server`), which the third-party switches rely on; the `mcp_adapter_default_server_config` filter and its `tools` key, which third-party exposure relies on; the `mcp_adapter_create_default_server` filter, read by the settings screen.
- **Resources and prompts.** Whether the default server still freezes them when it is built (see [Third-party abilities](#third-party-abilities)).

The quickest check after an upgrade: call `mcp-adapter-discover-abilities` from an MCP client and make sure this plugin's abilities are listed. If only other plugins' abilities come back, look at `meta.mcp.public` first.

### Release procedure

1. Set the new version in the plugin header (`Version:`), in `WPMCPA_VERSION`, and in `readme.txt` (`Stable tag:`). Add a `## [x.y.z] - YYYY-MM-DD` section to `CHANGELOG.md`, and the matching entries to the `Changelog` and `Upgrade Notice` sections of `readme.txt`.
2. Check that the numbers agree: `bin/check-versions.sh . vx.y.z`. It compares the header, the constant, the `Stable tag`, the tag and the changelog section, and refuses a `Stable tag` of `trunk`.
3. Commit, then tag and push: `git tag vx.y.z && git push origin vx.y.z`.
4. The `deploy.yml` workflow runs on `v*` tags. It runs the same version check, deploys to the WordPress.org SVN repository with `10up/action-wordpress-plugin-deploy` (the package follows `.distignore`, the directory assets come from `.wordpress-org/`), then creates the GitHub release with the changelog section as notes. It needs the `SVN_USERNAME` and `SVN_PASSWORD` repository secrets.

The `ci.yml` workflow runs on pushes and pull requests: PHP syntax check, `composer lint`, and Plugin Check on the built package.

To build the zip by hand: `wp dist-archive .` (WP-CLI's `dist-archive` command reads `.distignore`). The patterns in `.distignore` are deliberately unanchored, because `dist-archive` 2.0.1 mishandles leading slashes.

## Migrating from bsaweb-mcp-abilities

WP MCP Abilities is a standalone fork of bsaweb-mcp-abilities 2.5.1, with the same abilities and behaviour. It no longer needs the former dependency-injection framework, and the MCP Adapter is optional. Everything carrying the old name was renamed:

| Item | bsaweb-mcp-abilities 2.5.1 | WP MCP Abilities |
| --- | --- | --- |
| Plugin folder and slug | `bsaweb-mcp-abilities` | `wp-mcp-abilities` |
| Main file | `bsaweb-mcp-abilities.php` | `wp-mcp-abilities.php` |
| Composer package | `bsaweb/bsaweb-mcp-abilities` | `fyrins/wp-mcp-abilities` |
| PHP namespace | `Bsaweb\McpAbilities` | `WpMcpAbilities` |
| Constants | `BSAWEB_MCP_ABILITIES_*` | `WPMCPA_VERSION`, `WPMCPA_FILE`, `WPMCPA_DIR` (the kernel name constant is gone) |
| Ability category and prefix | `bsaweb`, `bsaweb/<name>` | `wp-mcp-abilities`, `wp-mcp-abilities/<name>` |
| Meta mark | `meta.bsaweb.plugin` | `meta.wpmcpa.plugin` |
| Filters | `bsaweb_mcp_abilities_*` | `wpmcpa_*` (same suffixes) |
| Options | `bsaweb_mcp_abilities_enabled`, `bsaweb_mcp_abilities_exposed` | `wpmcpa_enabled`, `wpmcpa_exposed` |
| Permission probe error code | `bsaweb_permission_probe` | `wpmcpa_permission_probe` |
| Text domain | `bsaweb-mcp-abilities` | `wp-mcp-abilities` |
| Settings page slug | `bsaweb-mcp-abilities` | `wp-mcp-abilities` |
| Minimum WordPress | 6.8 | 6.9 |
| `Requires Plugins` | `mcp-adapter` | none |

Steps:

1. Deactivate and remove bsaweb-mcp-abilities, then install and activate WP MCP Abilities. With Composer: `composer remove bsaweb/bsaweb-mcp-abilities && composer require fyrins/wp-mcp-abilities`. The old package pulled `wordpress/mcp-adapter` as a dependency; if the adapter came from there, require it directly (`composer require wordpress/mcp-adapter`).
2. Settings are not migrated. Open Settings → MCP Abilities and set the switches again, including the destructive abilities and third-party exposure. The old options can be deleted: `wp option delete bsaweb_mcp_abilities_enabled bsaweb_mcp_abilities_exposed`.
3. Rename filters in your code, for instance: `grep -rl 'bsaweb_mcp_abilities_' wp-content/ | xargs sed -i '' 's/bsaweb_mcp_abilities_/wpmcpa_/g'` (GNU sed: `sed -i` without `''`).
4. Update MCP clients, prompts and scripts that name abilities: `bsaweb/list-posts` becomes `wp-mcp-abilities/list-posts`, and so on.
5. Code reading `meta.bsaweb.plugin`, or calling classes under `Bsaweb\McpAbilities`, must use the new names.

## Contributing

Issues and pull requests are welcome on [GitHub](https://github.com/Fyrins/wp-mcp-abilities). Before opening a pull request:

- keep to the existing conventions (one class per ability, explicit wiring in `Plugin.php`, capabilities checked against the target object);
- run `composer lint` and `bin/smoke.sh`;
- document any new ability, parameter or filter in this README, and add an entry under `[Unreleased]` in `CHANGELOG.md`;
- write commit messages following [Conventional Commits](https://www.conventionalcommits.org/).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
