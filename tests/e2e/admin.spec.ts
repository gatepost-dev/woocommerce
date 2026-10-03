// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0
import { expect, type Page } from '@playwright/test';
import {
  fillBook,
  logIn,
  orderIdAfterPlacing,
  orderNotes,
  test,
  useCheckout,
  useOrderTables,
  useSettings,
  wp,
} from './shop';

async function placeClassicOrder(page: Page): Promise<string> {
  await fillBook(page, useCheckout('classic'));
  await page.locator('#billing_country').selectOption('NG');
  await page.locator('#billing_first_name').fill('Ada');
  await page.locator('#billing_last_name').fill('Obi');
  await page.locator('#billing_address_1').fill('1 Test Road');
  await page.locator('#billing_city').fill('Abuja');
  await page.locator('#billing_state').selectOption('FC');
  await page.locator('#billing_phone').fill('08000000000');
  await page.locator('#billing_email').fill('ada@example.com');
  await page.locator('#billing_gatepost_postcode').fill('FC 01 Z99 ZZ 01');
  await page.locator('#place_order').click();
  return orderIdAfterPlacing(page);
}

for (const tables of [true, false]) {
  test(`shows the postcode in the orders list with order tables ${tables ? 'on' : 'off'}`, async ({
    page,
  }) => {
    useOrderTables(tables);
    useSettings({ secretKey: 'nipost_live_example' });
    const orderId = await placeClassicOrder(page);
    await logIn(page);
    await page.goto(
      tables ? '/wp-admin/admin.php?page=wc-orders' : '/wp-admin/edit.php?post_type=shop_order',
    );
    await expect(page.locator('th#gatepost_postcode')).toHaveText('Postcode');
    const row = page.locator(`tr#order-${orderId}, tr#post-${orderId}`);
    await expect(row.locator('td.column-gatepost_postcode')).toHaveText('FC-01-Z99-ZZ-01Checked');
  });
}

test('keeps a test key out of the settings', async ({ page }) => {
  useSettings({ secretKey: 'nipost_live_saved' });
  await logIn(page);
  await page.goto('/wp-admin/admin.php?page=wc-settings&tab=advanced&section=gatepost_postcode');
  await page.locator('#gatepost_wc_secret_key').fill('nipost_test_example');
  await page.getByRole('button', { name: 'Save changes' }).click();
  await expect(page.getByText('The key was not saved.')).toBeVisible();
  expect(wp('option', 'get', 'gatepost_wc_secret_key')).toBe('nipost_live_saved');
});

test('shows "Check failed" in the orders list when the gateway does not answer', async ({
  page,
}) => {
  useOrderTables(true);
  useSettings({ secretKey: 'nipost_live_example', gateway: 'no-response' });
  const orderId = await placeClassicOrder(page);
  await logIn(page);
  await page.goto('/wp-admin/admin.php?page=wc-orders');
  const cell = page.locator(`tr#order-${orderId} td.column-gatepost_postcode`);
  await expect(cell).toHaveText('FC-01-Z99-ZZ-01Check failed');
  expect(orderNotes(orderId)).toContain('was not checked');
});

const settingsPage = '/wp-admin/admin.php?page=wc-settings&tab=advanced&section=gatepost_postcode';

test('saves a live key and never prints it again', async ({ page }) => {
  useSettings({ secretKey: '' });
  await logIn(page);
  await page.goto(settingsPage);
  await page.locator('#gatepost_wc_secret_key').fill('nipost_live_example');
  await page.getByRole('button', { name: 'Save changes' }).click();
  await expect(page.getByText('Your settings have been saved.')).toBeVisible();
  expect(wp('option', 'get', 'gatepost_wc_secret_key')).toBe('nipost_live_example');
  await expect(page.locator('#gatepost_wc_secret_key')).toHaveValue('');
  await expect(page.getByText('A key is saved.')).toBeVisible();
  expect(await page.content()).not.toContain('nipost_live_example');
});

test('keeps the saved key when the key field stays empty', async ({ page }) => {
  useSettings({ secretKey: 'nipost_live_saved' });
  await logIn(page);
  await page.goto(settingsPage);
  await page.locator('#gatepost_wc_legacy').selectOption('reject');
  await page.getByRole('button', { name: 'Save changes' }).click();
  await expect(page.getByText('Your settings have been saved.')).toBeVisible();
  expect(wp('option', 'get', 'gatepost_wc_secret_key')).toBe('nipost_live_saved');
  expect(wp('option', 'get', 'gatepost_wc_legacy')).toBe('reject');
});

test('removes the saved key when the box is ticked', async ({ page }) => {
  useSettings({ secretKey: 'nipost_live_saved' });
  await logIn(page);
  await page.goto(settingsPage);
  await page.locator('#gatepost_wc_remove_key').check();
  await page.getByRole('button', { name: 'Save changes' }).click();
  await expect(page.getByText('Your settings have been saved.')).toBeVisible();
  expect(wp('eval', "echo get_option( 'gatepost_wc_secret_key', 'none' );")).toBe('none');
  await expect(page.locator('#gatepost_wc_remove_key')).toHaveCount(0);
});
