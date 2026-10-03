# Look up a postcode after the order, and never stop an order for a failed lookup

The plugin checks a postcode in two steps.

1. The format check runs on the store's server each time WooCommerce checks the checkout form. It needs no network. It refuses a postcode with a bad format, with a message that names the problem.
2. The lookup asks NIPOST's gateway whether the postcode exists. It runs once for each new order, after the customer places it, and only when the store has a live secret key.

The checkout block checks address fields each time the customer changes the address. A lookup at that point would send many requests for one order. NIPOST's levels 2 and up use credits, and every gateway call counts against the key's rate limit. One lookup after the order costs one request.

A lookup that fails never stops the order, whatever the failure is. NIPOST launched the postcode on 1 Oct 2026, and its gateway may be slow or down. A store loses a sale when a check of its own address data blocks a customer. So the plugin waits at most 3 seconds, with no retry. A failure marks the order `error`, with an order note that says why and what to do. A postcode that NIPOST does not know gets the status `invalid` and a note, but the order still goes through. NIPOST's records are new, so a real postcode can be missing, and the store can ask the customer.

The cost: the store learns about a wrong postcode after the order, not before. The format check catches most typos before the order.

The 3-second limit is firm with the cURL transport of WordPress, which sets a total time. With the streams transport, WordPress applies the limit to each socket operation, so a slow gateway can take longer. Most hosts use cURL.
