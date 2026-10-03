# Bundle the PHP SDK under the plugin's own namespace

The plugin checks postcodes with `gatepost/postcode`, the Gatepost PHP SDK. A WordPress site has no Composer of its own, so the plugin must ship the SDK inside its zip. Another plugin on the same site can ship another version of the same SDK, or of the PSR packages that it needs. PHP loads only one class for each name. The first plugin to load would win, and the other plugin would run with the wrong version.

WP-4 asks each Gatepost plugin to bundle the SDK under its own prefix. Strauss copies the SDK and its dependencies into `vendor-prefixed/`, and renames each namespace to start with `Gatepost\WooCommerce\Vendor\`. The plugin calls only the renamed classes. Composer runs Strauss after each install, and `scripts/build-zip` runs it again for each release.

The client of the SDK needs a PSR-18 HTTP client and a PSR-17 request factory. WordPress has neither. The plugin brings `nyholm/psr7` (MIT) for the messages and the factory, and its own small `WpTransport`, which sends each request with the WordPress HTTP API. That API applies the site's proxy settings, and the `pre_http_request` filter lets the tests answer each request. Guzzle would also work, but it is about ten times larger and brings its own HTTP stack.

DEP-3 asks for an ADR for each runtime dependency. The plugin has two: `gatepost/postcode` (Apache-2.0) and `nyholm/psr7` (MIT). The SDK brings four PSR interface packages (MIT).
