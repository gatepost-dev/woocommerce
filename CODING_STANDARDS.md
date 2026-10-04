# Coding standards

This repo follows two files. Both are part of its standard:

1. `spec/standards/CODING_STANDARDS.md` holds the rules for every Gatepost repo.
2. `spec/standards/languages/php.md` holds the rules for PHP. Part B applies to this repo.

Rules for this repo only: use no feature of PHP 8.2 or later. The CI job that runs the tests on PHP 8.1 is the guard. Plugin code calls the bundled SDK only through the prefixed namespace `Gatepost\WooCommerce\Vendor\`, never through `Gatepost\Postcode\`.
