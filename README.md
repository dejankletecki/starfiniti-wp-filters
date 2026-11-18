# Starfiniti Filters for WooCommerce

Product filters for the WooCommerce shop your theme already renders. The plugin adds filter conditions to the product query of the theme or page builder, so the shop keeps its own product cards, layout and pagination.

## Status

Version 1.11.0 filters the WooCommerce shop, category, tag and brand pages.

Working now:

- Filters by category (as a tree), brand, tag, attribute, price range, stock status and on sale
- Filters by product properties the store works out with its own code, as checkboxes or as a number range with a unit
- Counts next to every choice, recalculated for the current selection
- Products, result count, pagination, sorting and panel swap without a page reload, with working back and forward buttons
- Selected filters above the products, each with a remove button, and "Clear all"
- Mobile panel: a full-width "Filters" button opens the panel as a drawer on screens up to 768px, or up to the width the store sets. In the drawer every filter is a row that opens it
- Price slider with two handles
- Price as ready-made ranges to pick one from, worked out from the prices of the listed products
- Swatches: a taxonomy as round chips with a colour or a picture each, for example flower colours
- Long lists folded behind "more (N)" after as many values as the store sets
- Page links, "Load more" or infinite scroll
- Categories (or any taxonomy) as checkboxes that filter the list, or as links to their own pages: the whole tree, or one level at a time
- Checkboxes drawn the same in every browser, in the accent colour of the store
- Works with the Breakdance "Shop Page" element
- Horizontal panel: a row of buttons above the products, each opening its filter under it
- Page links under the products drawn the same in every store, in the colours and the font of the site
- Works with LiteSpeed Cache: its CSS and JS optimization leaves the panel alone
- Works with product archives a page builder draws itself from the main query: the Breakdance post loop and a Bricks query loop set as the main query
- A settings page under WooCommerce > Filters, the same settings through WP-CLI and the WordPress Abilities API
- English and Slovenian
- Filtered pages are `noindex`, also where Rank Math writes the robots tag
- Updates from GitHub releases on the normal WordPress update screens

Not yet: radio buttons and select as filter controls, product lists that run a query of their own (a builder loop that is not the main query) and the `[products]` shortcode. The canonical link stays with the SEO plugin of the store.

## Install and updates

Download `starfiniti-filters-<version>.zip` from the [latest release](https://github.com/dejankletecki/starfiniti-wp-filters/releases/latest) and upload it under **Plugins > Add New > Upload Plugin**.

From then on the plugin updates like any other. WordPress shows a new release on the Plugins and Updates screens, with its notes under "View details", and auto-updates can be switched on for it. The plugin reads the public release data from GitHub at most twice a day and sends nothing about the store.

Before WordPress installs a release, the plugin compares the downloaded ZIP with the SHA-256 checksum published next to it. When the two differ, or the checksum cannot be read, the update stops and the installed version stays.

A store that blocks outgoing requests with `WP_HTTP_BLOCK_EXTERNAL` has to allow `api.github.com`, `github.com` and `release-assets.githubusercontent.com` in `WP_ACCESSIBLE_HOSTS`.

## Usage

Put the `[sffilters]` shortcode where the panel should appear, for example in a sidebar widget or a page builder template of the shop page.

Choose the filters under **WooCommerce > Filters**: add a filter for any category, brand, tag or attribute, or for price, availability and sale, set its title and order, and pick how the product list continues (page links, "Load more" or infinite scroll). Until you save, the panel shows categories, brands, price, availability and on sale.

The panel comes in two layouts. `[sffilters]` is the vertical panel, for a sidebar. `[sffilters layout="horizontal"]` is a row of buttons, one per filter: a button opens its filter under it and says how many values are chosen. Place the row above the products, outside the container with the product list. Where the theme draws the WooCommerce loop, a store can add it with a hook:

```php
// Before the wrapper around the results opens (priority -1000), so the row is not part of what is swapped.
add_action( 'woocommerce_before_shop_loop', static fn() => print do_shortcode( '[sffilters layout="horizontal"]' ), -2000 );
```

On small screens both layouts open in the same drawer, where every filter is a row: a tap opens it, one at a time, and the row says how many values are chosen.

A taxonomy filter shows its terms in one of four ways. **Checkboxes** filter the current list and keep the choice in the URL. On a category page they offer every other category the listed products are in, so the shopper can cross two categories. **Links to their own pages** turn every term into a link to its archive, for example the category page, with a link back to all products. Use links when the category pages should stay the pages people and search engines land on. **Links, one level at a time** does the same for a large category tree: the shop lists the top categories, a category lists its subcategories, and a category without subcategories lists the ones beside it, with a link one level up. Where the whole tree is shown, as checkboxes or as links, the subcategories are folded behind an arrow next to their category. A branch is open from the start when it holds a chosen value or the category being viewed.

**Swatches** filter the list like checkboxes and draw each term as a round chip with its name under it. The store gives each chip a colour, a picture or both with the `sffilters_swatch` filter:

```php
add_filter(
	'sffilters_swatch',
	static function ( array $swatch, WP_Term $term, string $slug ): array {
		if ( 'color' === $slug ) {
			$swatch['color'] = (string) get_term_meta( $term->term_id, 'color', true ); // For example #b3261e.
			$swatch['image'] = (string) get_term_meta( $term->term_id, 'thumbnail', true ); // Address of a small picture.
		}

		return $swatch;
	},
	10,
	3
);
```

A filter with checkboxes or swatches can show only its first values: set "Show first" on the settings page, or run `wp sffilters limit color 6`. The rest fold away behind "more (N)". The fold is open from the start when it holds a chosen value.

The price filter shows in one of two ways. **Slider and fields** lets the shopper set both ends of the range. **Ready-made ranges** offers up to four ranges with the number of products in each, for example "Up to 13 €", "13 € – 15 €" and "From 15 €". The ranges are cut at the prices a quarter, a half and three quarters of the way through the listed products, rounded to two digits, so they follow the list as other filters narrow it. Choosing the chosen range again gives it up.

### Look

The panel takes its fonts and colours from the theme. Four custom properties set the rest, on `:root` or on `.sffilters-panel`:

```css
.sffilters-panel,
.sffilters-drawer {
	--sffilters-accent: #7c8a3a;      /* Ticked checkboxes, the slider, the main button. */
	--sffilters-accent-text: #fff;    /* The tick and the text of the main button. */
	--sffilters-border: #767676;      /* The checkbox outline. */
	--sffilters-radius: 3px;          /* Buttons. */
}
```

The page links under the products are drawn by the plugin, the same in every store, in the font of the page and the brand colour of the page builder (Breakdance, Bricks or the theme palette). To use another colour:

```css
.sffilters-results {
	--sffilters-page: #26261f;        /* The page being viewed, and the outline of a page link under the pointer. */
	--sffilters-page-text: #fff;      /* The number on the page being viewed. */
}
```

The "Filters" button on small screens is drawn the same in every store too: full width, in the accent colour, with an icon. To use another colour:

```css
.sffilters-bar {
	--sffilters-button: #26261f;      /* The button that opens the filters on a phone. */
	--sffilters-button-text: #fff;    /* Its text and icon. */
}
```

On a Breakdance site the accent falls back to the brand colour.

### Shops drawn by a page builder loop

A theme or page builder may draw the products of the shop and category pages itself, without the WooCommerce loop, for example with the Breakdance post loop on the product archive template, or with a Bricks query loop that has "Is main query" switched on. The products are filtered there as well. Three things tell the plugin where everything is:

1. Give the container that holds the result count, the sorting, the products and the page links the CSS class `sffilters-results`. The plugin swaps what is inside it.
2. Put `[sffilters_bar]` inside that container, where the selected filters should show. On small screens it also holds the "Filters" button.
3. Put `[sffilters_ordering]` inside it for the WooCommerce sorting dropdown.

The panel itself is the `[sffilters]` shortcode, as everywhere else.

In Bricks, the class goes into the CSS classes of the block, the shortcodes into Shortcode elements. The result count and the sorting can stay the Bricks elements for them, and the page links are the Bricks Pagination element of the main query. After a swap the plugin asks Bricks to load its lazy images.

When the layout turns into one column at another width than 768px, set the width at which the panel becomes a drawer:

```php
add_filter(
	'sffilters_script_config',
	static function ( array $config ): array {
		$config['mobile'] = '(max-width: 1023px)';

		return $config;
	}
);
```

The same filter holds the selectors of the product list and its page links, for a builder other than Breakdance.

### Product properties

Some of what a shop wants to filter by is not a taxonomy. A supplier may write "Material: forged steel with boron" into a product-level attribute, where the shop wants the choice "Forged steel". A store registers such a property with a function that works the value out for one product:

```php
add_filter(
	'sffilters_properties',
	static function ( array $properties ): array {
		$properties['material'] = array(
			'label'    => 'Material',
			'callback' => 'my_store_material', // Gets the product ID. Returns a value, a list of values or null.
		);
		$properties['length']   = array(
			'label'    => 'Length',
			'type'     => 'number',            // The callback returns a number or null. Shown as a range.
			'unit'     => 'cm',
			'share'    => 0.6,                 // Optional, see below.
			'callback' => 'my_store_length',
		);

		return $properties;
	}
);
```

A property name is a short key of lowercase letters, digits and underscores. Once registered, the property is a filter source like any other: add it under **WooCommerce > Filters** or with `wp sffilters add material`.

The plugin works the values out when a product is saved and keeps them with the product, text values as terms of a taxonomy without screens of its own and numbers as product meta. They are filtered and counted like every other filter, and there is no index to keep in sync. Run `wp sffilters rebuild` once after registering a property or changing its rules. It goes through every product.

With `share`, a property filter shows only where enough of the listed products have a value. With `0.6`, a length filter stays out of the way on the shop page and appears in a category where at least 60 % of the products have a length. A filter that is in use always shows.

### WP-CLI

```bash
wp sffilters list
wp sffilters sources
wp sffilters add pa_color --label="Colour" --position=2
wp sffilters move price 1
wp sffilters display price ranges
wp sffilters display color swatches
wp sffilters limit color 6
wp sffilters label cat "Product type"
wp sffilters display cat links
wp sffilters display cat level
wp sffilters share color 0.9
wp sffilters remove sale
wp sffilters pagination more
wp sffilters rebuild
wp sffilters reset --yes
```

### AI agents

On WordPress 6.9+ the plugin registers two abilities with the Abilities API: `starfiniti-filters/get-settings` and `starfiniti-filters/update-settings`. An agent connected through the MCP Adapter can read and change the same settings. Both need the `manage_woocommerce` capability.

### Code

Two filters change what the store saved:

```php
add_filter(
	'sffilters_filter_definitions',
	static function ( array $definitions ): array {
		$definitions[] = array( 'source' => 'pa_color' );

		return $definitions;
	}
);

add_filter( 'sffilters_pagination', static fn() => 'more' );

// Leave "Out of stock" out of the availability filter.
add_filter(
	'sffilters_facet',
	static function ( $facet, $slug ) {
		if ( 'stock' === $slug && ! empty( $facet['options'] ) ) {
			$facet['options'] = array_values( array_filter( $facet['options'], static fn( $option ) => 'outofstock' !== $option['value'] ) );
		}

		return $facet;
	},
	10,
	2
);
```

## How it works

1. A shopper picks a filter. The URL gets a parameter such as `?sf_brand=anker,xiaomi&sf_price=50-200`.
2. The plugin adds those conditions to the product query the theme already runs.
3. Counts next to each value come from WordPress and WooCommerce tables. There is no separate index to sync.
4. A small script loads the new URL in the background and swaps the products, result count, pagination and filter panel. Without JavaScript the panel is a normal form.

## Out of scope

External search engines, search and autocomplete, SEO landing pages for filter combinations and per-variation stock filtering.

## Requirements

WordPress 6.6+, WooCommerce 9.6+, PHP 8.1+.

## Local development

Node 22+ is required for the local store. It runs in WordPress Playground with WooCommerce, the Storefront theme and a small demo catalog.

```bash
npm install
npm run playground
```

The store opens at `http://127.0.0.1:9410/shop/`.

```bash
npm run lint:php
```

After changing a `.po` file in `languages/`, compile it:

```bash
npm run i18n
```

### Tests

```bash
npm test
```

This starts the same store on port 9411, runs `tests/checks.php` inside it and then asks the shop pages for filtered lists the way a visitor would. The checks work out what each filter should return from the products themselves and compare it with what the plugin returns. They also cover the counts in the panel, the settings and the updates, with GitHub played by fixed answers.

On GitHub the same checks run in Playground and on MariaDB, with the lowest supported and the latest WordPress and WooCommerce.

### Releasing

1. Set the new version in `package.json`, in the plugin header and in `SFFILTERS_VERSION`, and describe it in `CHANGELOG.md`.
2. Commit, tag the commit `v<version>` and push the tag.
3. GitHub Actions runs every check, builds the ZIP with its checksum and publishes the release with the changelog section as its notes.

`npm run package` builds the same ZIP locally in `dist/`, from the files of the last commit.

## History

This repository previously held a larger search and filtering candidate. That work is frozen on the `codex/resume-infrastructure` branch and the `archive/enterprise-2026-09` tag.

## License

GPL-3.0-or-later. See `LICENSE`.
