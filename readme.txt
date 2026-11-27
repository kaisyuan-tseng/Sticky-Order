=== Sticky Order ===
Contributors: kaisyuan-tseng
Tags: sticky, order, queue, rest, headless
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn sticky posts into an ordered queue. The WordPress sticky flag decides which posts are
sticky; Sticky Order decides their order, independent of publish dates. Queues follow your
categories automatically, and front-end and REST post lists use the saved order.

== Description ==

WordPress core exposes sticky as a boolean. A post is either sticky or not, and the
order among sticky posts still comes from the publish date. Promoting an older post
therefore means backdating it, which also changes the date shown on the article, its
position in archives, and the related-posts list.

This plugin splits membership from order. Membership still uses the native sticky
checkbox in the post editor; order lives in a separate option and is edited by dragging
rows on the Sticky Order page under Posts. Publish dates are never touched.

The saved order is applied at the query layer. Queued sticky posts are pinned to the top
of front-end and REST post lists in queue order, then the rest of the list keeps its
original order. Ordering happens in SQL before the page limit, so pagination,
`found_posts`, and REST `per_page` stay consistent.

What it actually does:

* Splits queues by any taxonomy, one independent queue per group. Groups are the site's
  existing categories by default; override `sticky_order_groups` to customize, or return
  an empty array for one shared queue. A post in several groups appears in each queue with
  its own position.
* Pins queued sticky posts to the top of front-end and REST post lists, before pagination.
  Admin list tables are not reordered.
* Exposes three read-only REST fields: `sticky_order_position`, `sticky_order_queue`,
  and `sticky_order_positions`, for custom front ends. They are optional: the REST
  collection order is already the queue order.
* Appends newly stickied posts to the tail in reverse-date order; they never jump ahead.
* Drops posts automatically when they are unstickied or unpublished. No stale IDs to clean.
* Lists sticky posts that match no group at the bottom of the admin page, so a post
  never silently disappears; those posts are ordered after every queued post.
* Disabling the plugin simply removes the query order and REST fields; consumers fall back
  to the query's own order.

The plugin contains no site-specific term names, post types, or UI strings. Groups, labels,
capability, menu location, option name, and post types are all injected through filters,
so the directory can be copied to any other site as is. Admin UI strings use gettext:
source msgids are English and a Simplified Chinese catalog is bundled in `/languages`.
Any site may override the labels through the `sticky_order_labels` filter.

== Installation ==

1. Upload the release zip through Plugins → Add New → Upload Plugin. The zip must contain
   exactly one `sticky-order/` folder with `sticky-order.php` at its root. If you build from
   source, run `php build-zip.php` and upload `dist/sticky-order-<version>.zip`; do not zip
   the working directory by hand, because it also contains `.git`, `scripts/`, and
   `build-zip.php`.
2. Activate "Sticky Order" on the Plugins screen.
3. Groups are auto-detected from your categories. Override `sticky_order_groups` to
   customize, or return an empty array for single-queue mode.
4. Reorder the queue on the Sticky Order page. Front-end and REST post lists pick up the
   order automatically; rebuild static output after reordering.

== Frequently Asked Questions ==

= Will deactivating the plugin lose the saved order? =

No. Deactivation removes the query order and the REST fields, so lists fall back to their
own order; the queue option is kept. Re-activating restores the order. The option is deleted
only when the plugin is deleted, via `uninstall.php`.

= What does uninstalling remove? =

The plugin creates no files, directories, custom tables, cron events, or transients. On
deletion, `uninstall.php` removes the queue option and every `sticky_order_*` option on
every site in a multisite network, then leaves the core `sticky_posts` option untouched.
Custom option names set through `sticky_order_option_name` must keep the `sticky_order_`
prefix to be included in that sweep.

= What language is the admin UI in? =

Source strings are English; a Simplified Chinese catalog is bundled in `/languages` and
loads automatically when the site locale is `zh_CN`. Only the plugin name in the Plugins
list stays "Sticky Order" in every locale; the page title, the menu label, and every other
admin string follow the site locale. On other locales the UI is English unless you override
it with the `sticky_order_labels` filter. Keep the plugin folder and its `languages/`
directory together when deploying.

= How do I set up groups? =

By default, groups are the site's existing terms in the taxonomy from
`sticky_order_group_taxonomy` (`category` by default), so a normal WordPress install works
without configuration. Empty categories are shown; empty Uncategorized system terms are
skipped. To override, return a `slug => label` map from the `sticky_order_groups` filter.
Return an empty array for a single shared queue. An example override lives in
`wp-content/mu-plugins/sticky-order-config.php`.

= Can a post belong to more than one queue? =

Yes. If a post is in multiple group categories, it appears in each category's queue with
its own independent position. The single-value fields report the primary queue (the first
group in group order); use `sticky_order_positions` for all positions. Single-category
queries use that category's queue; unfiltered or multi-category queries use the primary
queue order.

= Does my front end need to sort posts? =

No. The queue is applied to front-end and REST post lists before pagination, so a front
end that renders the API order directly already shows the queue. The REST fields are
optional for custom UIs. If your front end sorts by `sticky_order_position`, that sort is
redundant; if it sorts by date or its own field, remove that sorting so it does not
override the server order.

= Does this affect admin screens or `get_posts()`? =

Admin queries are not reordered. `get_posts()` defaults to `suppress_filters => true`, so
those calls are also left alone; use `WP_Query`, the main query, or the REST API when you
want the queue order. To opt out for one query, set `sticky_order_skip => true` in the
query args or return `false` from `sticky_order_apply_to_query`.

== Hooks ==

`sticky_order_groups` (array)
  Group map `array( slug => label )`; slugs match term slugs. Default is the current
  taxonomy's terms, auto-detected. Return an empty array for single-queue mode.

`sticky_order_group_taxonomy` (string)
  Taxonomy used for grouping. Default `category`.

`sticky_order_post_types` (array)
  Post types included. Default `array( 'post' )`.

`sticky_order_option_name` (string)
  Option holding the queues. Default `sticky_order_queue_map`.

`sticky_order_capability` (string)
  Capability required for the admin page. Default `edit_others_posts`.

`sticky_order_menu_parent` (string)
  Parent admin menu. Default `edit.php`.

`sticky_order_labels` (array)
  Admin UI labels. Missing keys fall back to the defaults.

`sticky_order_apply_to_query` (bool)
  Whether to apply the queue order to a query. Default `true` on front-end and REST lists
  for the supported post types; return `false` to leave a query in its original order.

== REST ==

Three read-only fields are added to the post response. They are optional: the REST
collection order is already the queue order, so a front end can render it directly.

`sticky_order_position` (integer|null)
  Position in its primary sticky queue, starting at 0. `null` when the post is not queued.
  In grouped mode this is the position in the primary group; it is not comparable across
  groups.

`sticky_order_queue` (string|null)
  Key of the primary queue; `default` in single-queue mode.

`sticky_order_positions` (object|null)
  All queue positions for the post, keyed by queue, for example
  `{ "guides": 1, "applications": 3 }`. `null` when the post is not queued.

Posts that are not sticky return `null` for all three fields. Use them only when a custom
UI needs to know queue keys or positions; basic ordering does not require them.

== Storage ==

Option `sticky_order_queue_map`, for example:

    array(
        'section-a' => array( 28 ),        // array order = queue order
        'section-b' => array( 60, 61 ),
    )

Only IDs that are currently sticky and published are used; everything else is
filtered out at read time.

