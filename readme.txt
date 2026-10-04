=== Gatepost Postcode for WooCommerce ===
Contributors: gatepostdev
Tags: postcode, nigeria, checkout, address, validation
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: Apache-2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0

Unofficial. Not made or endorsed by NIPOST. Checks Nigeria's new 11-character postcodes at checkout.

== Description ==

Unofficial. Not made or endorsed by NIPOST.

Nigeria's digital postcodes have 11 characters, such as FC-01-Z99-ZZ-01. WooCommerce hides the postcode field for Nigeria. This plugin adds a postcode field to Nigerian addresses in the classic checkout and in the checkout block.

* The field shows only when the address is in Nigeria.
* The plugin checks the format while the customer fills in the form. It names the problem and suggests a fix for common typos, such as the letter O in place of a zero.
* The plugin stores the postcode in its standard form, and copies it into the address of the order, so shipping plugins see it.
* You choose whether the field is required, and whether the store accepts old 6-digit postcodes.
* With your own live secret key from NIPOST, the store asks NIPOST whether each postcode exists, after the customer places the order. A failed lookup never stops an order: the plugin marks the order and leaves a note.
* The orders list shows the postcode and the result of the check.

The plugin works with order tables (HPOS) and with orders stored as posts.

== Installation ==

1. Install and activate WooCommerce 10.0 or later.
2. This plugin is not on WordPress.org yet. Install its zip file from https://github.com/gatepost-dev/woocommerce with Plugins > Add New Plugin > Upload Plugin, and activate it.
3. Go to WooCommerce > Settings > Advanced > Nigerian postcodes.
4. Choose whether the field is required, and what to do with old 6-digit postcodes.
5. To look up each postcode, enter a live secret key from NIPOST. It starts with nipost_live_. The plugin does not issue keys. You need your own key from NIPOST.

== Frequently Asked Questions ==

= Does the plugin need a key from NIPOST? =

Not for the format check. Without a key, the plugin checks the format of each postcode on your server and sends nothing to NIPOST. The lookup needs a key from NIPOST, which the plugin does not issue.

= What happens when NIPOST's service is slow or down? =

The plugin still places the order. It waits at most 3 seconds for each postcode, marks the order "Check failed" and leaves an order note that says why.

= Does the plugin work with the export and erase tools of WordPress? =

Yes. The postcodes of an order and of a customer's saved address appear in the personal data export. The erase request removes them, and keeps the check status, which is not personal data. The plugin also adds a suggested text to the privacy policy page of your store.

= Can I use a test key? =

No. NIPOST's test keys work only on its staging service, which this plugin does not use. Use a live secret key.

== External services ==

This plugin can connect to NIPOST's postcode gateway at https://api.postcode.gov.ng. It does so only when the store owner enters a live secret key and keeps the lookup setting on.

* When: after the customer places an order with a Nigerian address, one request for each different postcode of the order. The two addresses share one request when they hold the same postcode. A payment retry reuses the order. The order remembers the answer for each address, valid or invalid, with a keyed hash of the postcode. A retry sends only a postcode that has no answer yet, or a postcode that changed. A postcode whose request failed is sent again on the next retry. An address that leaves Nigeria and comes back with the same postcode is not sent again. The plugin never retries by itself.
* What it sends: the postcode in its standard form, and the store's secret key in the X-API-Key header. The User-Agent header is the fixed text gatepost-postcode-for-woocommerce. It holds no WordPress version and no address of your shop. The plugin sends no name, email address or other part of the address. It never sends an old 6-digit postcode. NIPOST also sees the IP address of the store's server with each request.
* What it keeps: the postcode and the result of the check (valid, invalid, unchecked or error) as order meta. It keeps nothing else from the response. Order notes and the WooCommerce log name a postcode with its last part hidden.

The Nigerian Postal Service (NIPOST) runs the gateway. Its terms of use: https://postcode.gov.ng/terms. Its acceptable use policy: https://postcode.gov.ng/acceptable-use. Its privacy policy: https://postcode.gov.ng/privacy. Read all three before you enter a key.

== Changelog ==

= 0.1.0 =

* First release.
