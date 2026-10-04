# Accept that a block order shows the postcode twice

The checkout block saves the postcode as an additional address field, and the plugin copies it into the native postcode of the address, so that shipping plugins and labels see it. WooCommerce prints each additional address field under the address. So an order from the checkout block shows the postcode in the address and again as a "Postcode" line, for billing and for shipping.

A check of the order page for customers, the HTML email and the plain text email shows the repeat in all three. WooCommerce gives a field no setting that hides it from these displays. A hook that removes the line would change what WooCommerce prints for every additional field.

The plugin accepts the repeat. The native postcode must hold the value, because shipping plugins read it, and the additional field must stay, because it holds the value that the plugin checked. The line costs a customer nothing, and it shows the value in the standard form. The check did not cover the admin order screen.

Revisit this if WooCommerce adds a way to hide one additional field from the displays of an order.
