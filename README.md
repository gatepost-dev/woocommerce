# Gatepost Postcode for WooCommerce

Check Nigeria's digital postcodes in the WooCommerce checkout.

> Unofficial. Not made or endorsed by NIPOST.

[![CI](https://github.com/gatepost-dev/woocommerce/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/gatepost-dev/woocommerce/actions/workflows/ci.yml)
[![Licence](https://img.shields.io/badge/licence-Apache--2.0-blue)](https://github.com/gatepost-dev/woocommerce/blob/main/LICENSE)

## Install

Download `gatepost-postcode-for-woocommerce.zip` from the newest GitHub release. In WordPress, go to Plugins > Add New > Upload Plugin, and upload the zip.

## Quickstart

1. Activate WooCommerce 10.0 or later, then this plugin.
2. Open WooCommerce > Settings > Advanced > Nigerian postcodes.
3. Optional: enter your live secret key from NIPOST, which starts with `nipost_live_`.

A customer with a Nigerian address now sees a Postcode field in both checkouts. The order keeps the postcode in its standard form, such as `FC-01-Z99-ZZ-01`.

## What it does

- Shows a postcode field for Nigerian addresses only, in the classic checkout and in the checkout block.
- Checks the format at checkout, names the problem, and suggests a fix for common typos.
- Accepts or rejects old 6-digit postcodes, as the store chooses.
- Looks up each postcode with the store's own key after the order. A failed lookup never stops an order.
- Copies the postcode into the order address, and shows it in the orders list, with order tables (HPOS) on or off.

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.1 or later |
| WordPress | 6.7 or later |
| WooCommerce | 10.0 or later |
| Gatepost spec | 0.2.0 |

## Docs

`readme.txt` holds the WordPress.org listing, with the details of the lookup and of what it sends. `docs/adr/` holds the decisions behind the plugin.

## Support

Ask questions in GitHub Discussions. Report bugs in GitHub Issues. Report security problems privately, as `SECURITY.md` describes.

## Contributing

Read `CONTRIBUTING.md` before you open a pull request. Run `scripts/install-wp`, `composer check` and `corepack pnpm test:e2e`. None of them needs Docker.

## Licence

Apache-2.0. See `LICENSE` and `NOTICE`.
