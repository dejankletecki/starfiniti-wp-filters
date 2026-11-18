# Changelog

## Unreleased

## 1.11.1

- The rows in the drawer keep the same distance in stores that part the filters of their panel with a line and space of their own.

## 1.11.0

- In the drawer on small screens every filter is now a row that opens it, for the vertical panel too: one filter is open at a time and a row says how many values are chosen. Until now only the horizontal panel was laid out that way, and the vertical one showed all its filters open, one under the other.
- The "Filters" button on small screens is now a full-width button in the accent colour with an icon, the same in every store. `--sffilters-button` and `--sffilters-button-text` set another colour.
- The bar with that button takes the full width of its row, also where the page builder puts it in a row of its own.

## 1.10.2

- Fixed the horizontal panel in the drawer on small screens: an open filter with a long value was wider than the drawer, so the counts and the arrows of the rows were pushed out of sight.

## 1.10.1

- Fixed the panel inside the Breakdance shop element, where the horizontal row lands when it is added with the WooCommerce loop hook. The builder styled the labels and checkboxes as checkout fields: a long name wrapped under its checkbox and the count dropped to a line of its own.
- An open filter of the horizontal panel is now as wide as its longest value, from 300 to 420 pixels.

## 1.10.0

- Added the horizontal panel, `[sffilters layout="horizontal"]`: a row of buttons above the products, each opening its filter under it. A button says how many values are chosen, one filter is open at a time, and it closes on a click outside it or on Escape. In the drawer on small screens the filters are a list.
- A filter the shopper opened stays open after the list is filtered also where the panel sits inside the results.

## 1.9.0

- The page links under the products are now drawn by the plugin, the same in every store: a row of boxes with the page being viewed filled in, for the WooCommerce loop, the Breakdance post loop and Bricks. The font is the one of the page, the corners follow the buttons of the site, and the colour is the brand colour of the page builder. A store sets another colour with `--sffilters-page` (and `--sffilters-page-text`) on `:root` or on `.sffilters-results`. Before, each builder drew them its own way, and where a store had styled the page links of its old filter plugin, they came out as bare text.

## 1.8.0

- Added swatches, a fourth way to show a taxonomy: checkboxes drawn as round chips with a colour or a picture each. The store gives the colour and the picture with the new `sffilters_swatch` filter.
- A filter with checkboxes or swatches can fold its values after the first few behind "more (N)": set "Show first" on the settings page, or run `wp sffilters limit <slug> <number>`.

## 1.7.1

- Works with the CSS and JS optimization of LiteSpeed Cache out of the box. With "Unique CSS" or Guest optimization switched on, the panel could be drawn without its styles, and with delayed JavaScript the first click on a filter was lost. The stylesheet and the script of the panel are now kept out of that optimization.

## 1.7.0

- The price filter can show as ready-made ranges to pick one from, in place of the slider: set "Ready-made ranges" on the settings page, or run `wp sffilters display price ranges`. The panel offers up to four ranges with the number of products in each, cut at the prices a quarter, a half and three quarters of the way through the listed products. Choosing the chosen range again gives it up.

## 1.6.2

- Fixed the panel slowing down with the size of the category tree. The plugin looked every category up on its own; on a shop with 19,000 categories that was 18,920 database queries and more than two seconds on every shop page. The parents of all categories are now read with one query, and "Links, one level at a time" loads only the categories of the level it shows.
- Fixed the handles of the price slider taking the text colour the theme gives to inputs. They take the accent colour of the store, like the track between them.

## 1.6.1

- On a category page the category checkboxes now offer every other category the listed products are in, not only the subcategories of that page. A shopper on "Mugs" can narrow the list to "Gifts for him". The category being viewed and the ones above it are left out, because they hold every listed product.

## 1.6.0

- Subcategories now fold away behind an arrow next to their category, in the checkbox tree and in the tree of links. A branch starts open when it holds a chosen value or the category being viewed, and a branch the shopper opened stays open while they filter. It works without the script.
- The plugin moved to a new repository on GitHub, and updates are read from there. A store on 1.5.0 or older does not find this version by itself: install it once from the ZIP of the release, later updates arrive in WordPress again.
- An update is also accepted after the repository moves to the Starfiniti organization on GitHub, so a store keeps finding its updates.

## 1.5.0

- A taxonomy filter with checkboxes can show only when enough of the listed products have a term: set the share on the settings page ("Show from"), with `wp sffilters share` or through the abilities. A filter for an attribute that few products of a category have then stays out of the way there.
- Added the `sffilters_facet` filter. A store can leave a value out of a filter, sort the values or hide a filter on some pages.
- Added the Croatian translation.
- Sizes in the stylesheet are now in pixels and em, none in rem, so the panel and the drawer keep their size in themes that change the root font size, as Bricks does.

## 1.4.3

- Fixed checkboxes that came out wider than tall where the theme gives every input a padding, as Bricks does.
- The labels of the options now take the colour of the panel, not the colour the theme sets for form labels.

## 1.4.2

- Fixed the checkboxes sitting a little below their labels. The box is now in the middle of the first line of its label, whatever the font and line height of the store.

## 1.4.1

- Fixed the Breakdance shop element: the result count and the sorting stay next to each other, as the store laid them out. The wrapper around the results had put them one under the other.

## 1.4.0

- Added support for Bricks: a query loop set as the main query is filtered, its page links and "Load more" work without a page reload, and lazy images load after a swap. Checked with Bricks 2.4 and B2BKing 5.6.
- Filtered pages are now `noindex` also where Rank Math writes the robots tag.

## 1.3.0

- Added a third display for taxonomy filters, "Links, one level at a time" (`level`): the shop lists the top categories, a category lists its subcategories, and a category without subcategories lists the ones beside it, with a link one level up. The whole tree stays available as "Links to their own pages".
- Checkboxes are now drawn by the plugin, so they look the same in every browser. A store sets their colour with `--sffilters-accent`, the outline with `--sffilters-border`. The slider takes the same accent.

## 1.2.0

- Added filters by product properties a store works out with its own code. A store registers a property with the `sffilters_properties` filter and a function. Text values show as checkboxes with counts, numbers as a range with a unit. The values are worked out when a product is saved and by `wp sffilters rebuild`.
- A property filter can be limited with `share` to lists where enough of the products have a value, so it shows in the categories it belongs to.
- Uninstalling the plugin also removes the property values it stored.

## 1.1.0

- Added support for product archives a page builder draws itself from the main query, such as the Breakdance post loop. The container with the class `sffilters-results` is swapped without a page reload, `[sffilters_bar]` shows the selected filters and the "Filters" button, `[sffilters_ordering]` shows the WooCommerce sorting dropdown.
- The width below which the panel opens as a drawer can be changed with the `mobile` media query in the `sffilters_script_config` filter. It stays 768px by default.

## 1.0.0

- First stable release. The code is the same as in 0.6.0.
- Not part of 1.0.0: radio buttons and select as filter controls, a canonical link on filtered pages, Bricks query loops, Breakdance product lists outside the shop template and the `[products]` shortcode.

## 0.6.0

- Added updates from GitHub releases. A new release shows up on the WordPress Plugins and Updates screens, with its notes under "View details". Before WordPress installs it, the package is compared with its published SHA-256 checksum.
- Added `npm test`: checks of filtering, counts, settings and updates inside a real WordPress with WooCommerce. On GitHub they run in WordPress Playground and on MariaDB.
- Added `npm run package` and a release job: pushing a `v<version>` tag publishes the ZIP and its checksum.

## 0.5.0

- Added a display setting for taxonomy filters: checkboxes that filter the list, or links that lead to each term's own page, with a link back to all products on those pages. Set it on the settings page, with `wp sffilters display` or through the abilities.
- Buttons (mobile "Filters" button, "Show products", "Load more") now have a plain style of their own where the theme styles none, as in Breakdance. A theme that styles `.button` keeps its look.
- Confirmed with the Breakdance "Shop Page" element: filtering, selected filters, infinite scroll, the mobile drawer and add to cart after a swap.
- Fixed a PHP warning for a filter definition without a label.

## 0.4.0

- Added the settings page under WooCommerce > Filters: add, title, reorder and remove filters, and choose page links, "Load more" or infinite scroll.
- Added WP-CLI commands: `wp sffilters list`, `sources`, `add`, `remove`, `move`, `label`, `pagination` and `reset`.
- Added two abilities for the WordPress Abilities API (WordPress 6.9+): `starfiniti-filters/get-settings` and `starfiniti-filters/update-settings`.
- A filter definition may name its taxonomy directly as `source`, for example `pa_color`.
- A store that saves an empty list of filters now shows no panel. Before, the default filters came back.

## 0.3.0

- Added a bar above the products with one removable chip per selected value and a "Clear all" link.
- Added the mobile panel: on screens up to 768px a "Filters" button opens the panel as a drawer.
- Added a two-handle price slider above the price inputs.
- Added "Load more" and infinite scroll as alternatives to page links, set with `pagination` in `sffilters_settings` or the `sffilters_pagination` filter. After Back from a product the loaded pages and the scroll position return.
- The sorting dropdown now reorders the list without a page reload.
- Added the Slovenian translation.

## 0.2.0

- Added filtering of the WooCommerce shop and product archive pages by category, brand, tag, attribute, price range, stock status and on sale. Filter state lives in the URL as `sf_<slug>` parameters.
- Added the `[sffilters]` panel with counts next to every choice. Counts come from the WordPress and WooCommerce tables.
- Added background loading: products, result count, pagination and the panel swap without a page reload. Without JavaScript the panel is a normal form.
- Filtered pages are `noindex`.

## 0.1.0

- Restarted as a small, filters-only plugin. The earlier search and filtering candidate is frozen on the `codex/resume-infrastructure` branch and the `archive/enterprise-2026-09` tag.
- Added the plugin skeleton, a WordPress Playground store with a demo catalog, and a PHP lint check.
