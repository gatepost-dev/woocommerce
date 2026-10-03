// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0
import { execFileSync } from 'node:child_process';
import { expect, test as base, type Page } from '@playwright/test';

// Runs WP-CLI on the shop that scripts/e2e-site built.
export function wp(...args: string[]): string {
  return execFileSync(
    'php',
    ['-d', 'memory_limit=1G', 'build/wp-cli.phar', '--path=build/e2e', '--quiet', ...args],
    { encoding: 'utf8' },
  ).trim();
}

// Sets the plugin's options and the fake gateway's answer for one test.
export function useSettings(settings: {
  secretKey?: string;
  required?: 'yes' | 'no';
  legacy?: 'accept' | 'reject';
  gateway?: string;
}): void {
  wp('option', 'update', 'gatepost_wc_secret_key', settings.secretKey ?? '');
  wp('option', 'update', 'gatepost_wc_required', settings.required ?? 'no');
  wp('option', 'update', 'gatepost_wc_legacy', settings.legacy ?? 'accept');
  wp('option', 'update', 'gatepost_e2e_gateway', settings.gateway ?? 'fixtures');
}

// Makes the checkout block page or the classic checkout page the shop's checkout.
export function useCheckout(kind: 'block' | 'classic'): string {
  const slug = kind === 'block' ? 'checkout' : 'classic-checkout';
  const id = wp('post', 'list', '--post_type=page', `--name=${slug}`, '--field=ID');
  wp('option', 'update', 'woocommerce_checkout_page_id', id);
  return `/?page_id=${id}`;
}

// Turns WooCommerce's order tables (HPOS) on or off. Both stores stay in sync.
export function useOrderTables(on: boolean): void {
  wp('option', 'update', 'woocommerce_custom_orders_table_enabled', on ? 'yes' : 'no');
}

export async function fillBook(page: Page, checkoutPath: string): Promise<void> {
  const book = wp('post', 'list', '--post_type=product', '--name=book', '--field=ID');
  await page.goto(`/?add-to-cart=${book}`);
  await page.goto(checkoutPath);
}

// Reads the plugin's meta of an order.
export function orderMeta(orderId: string, key: string): string {
  return wp('eval', `echo wc_get_order( ${orderId} )->get_meta( '${key}' );`);
}

export async function orderIdAfterPlacing(page: Page): Promise<string> {
  await page.waitForURL(/order-received=(\d+)/);
  const id = new URL(page.url()).searchParams.get('order-received');
  expect(id).not.toBeNull();
  return id ?? '';
}

// The test of every spec. It fails a test when the shop or the browser calls a host other than
// the shop itself, because the only call that a test may cause is the one to the fake gateway.
export const test = base.extend<{ noOutsideCalls: void }>({
  noOutsideCalls: [
    async ({ page }, use) => {
      wp('option', 'delete', 'gatepost_e2e_blocked');
      const hosts = new Set<string>();
      page.on('request', (request) => {
        const url = new URL(request.url());
        if (['http:', 'https:'].includes(url.protocol) && url.hostname !== '127.0.0.1') {
          hosts.add(url.hostname);
        }
      });
      await use();
      const blocked = wp(
        'eval',
        "echo implode( ' ', (array) get_option( 'gatepost_e2e_blocked' ) );",
      );
      expect({ browser: [...hosts], shop: blocked }).toEqual({ browser: [], shop: '' });
    },
    { auto: true },
  ],
});
