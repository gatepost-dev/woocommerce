# Support PHP 8.1, WordPress 6.7 and WooCommerce 10.0

VER-1 asks each repo to state its runtime floors, with an ADR for a floor that is past its end of life.

## WooCommerce 10.0

The checkout block shows the plugin's field only for a Nigerian address. The Additional Checkout Fields API does this with a `hidden` rule, and WooCommerce 9.9.7 has the rule. On WooCommerce 9.9.7, the server still checked a hidden address field: with the field required, a customer outside Nigeria could not place an order. On WooCommerce 10.0.0 and 10.0.4, the server skips the hidden field, every test passes, and one test is marked risky. The tests `BlockCheckoutTest::test_requires_a_postcode_in_nigeria_only_when_the_store_says_so` and `test_ignores_the_field_for_an_address_outside_nigeria` show the difference. So the floor is WooCommerce 10.0, and the plugin header says `WC requires at least: 10.0`.

We ran these tests on 3 Oct 2026 with PHP 8.4.14, the WordPress 7.1.2 test library and a SQLite database. We ran WooCommerce 9.9.7 and 10.0.0 on the 12 tests of `BlockCheckoutTest`, and WooCommerce 10.0.4 on the whole suite. We did not run WordPress 6.7.9 or PHP 8.1 for this evidence. The CI floor job runs that combination.

The risky mark comes from deprecation notices that WooCommerce 10.0 prints from its own code on PHP 8.4.

On 3 Oct 2026, the WordPress.org statistics (https://api.wordpress.org/stats/plugin/1.0/woocommerce) showed 34.5 % of WooCommerce sites on 11.1, 7.8 % on 11.0 and 8.1 % on 10.7. They group the other 49.5 % and do not show how many of those sites run 10.0 or later.

## WordPress 6.7

WooCommerce 10.0 needs WordPress 6.7, so the plugin needs it too. On 3 Oct 2026, the WordPress.org statistics (https://api.wordpress.org/stats/wordpress/1.0/) showed 85.2 % of sites on WordPress 6.7 or later.

## PHP 8.1

PHP 8.1 reached its end of life on 31 Dec 2025. The plugin bundles `gatepost/postcode`, whose ADR 0001 keeps PHP 8.1 for the stores that still run it. On 3 Oct 2026, the WordPress.org statistics (https://api.wordpress.org/stats/php/1.0/) showed 11.1 % of WordPress sites on PHP 8.1. The plugin keeps the same floor.

## Cost

CI runs the tests on the floors (PHP 8.1, WordPress 6.7.9, WooCommerce 10.0.4) and on the newest releases (PHP 8.5, WordPress 7.1.2, WooCommerce 11.1.2). The floor job installs the WordPress test library of WordPress 6.7, because each version of the library expects its own WordPress. Check these numbers again before each minor release, and raise a floor only in a minor release (VER-2).
