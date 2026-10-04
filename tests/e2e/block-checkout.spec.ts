// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';
import {
  fillBook,
  logIn,
  orderAddressPostcode,
  orderIdAfterPlacing,
  orderMeta,
  orderNotes,
  test,
  useOrderTables,
  useCheckout,
  useSettings,
} from './shop';

async function fillAddress(page: Page, postcode: string): Promise<void> {
  await page.locator('#email').fill('ada@example.com');
  await page.locator('#shipping-country').selectOption('NG');
  await page.locator('#shipping-first_name').fill('Ada');
  await page.locator('#shipping-last_name').fill('Obi');
  await page.locator('#shipping-address_1').fill('1 Test Road');
  await page.locator('#shipping-city').fill('Abuja');
  await page.locator('#shipping-state').selectOption('FC');
  await page.locator('#shipping-gatepost-postcode').fill(postcode);
}

test.beforeEach(async ({ page }) => {
  useSettings({ secretKey: 'nipost_live_example' });
  await fillBook(page, useCheckout('block'));
});

test('shows the postcode field for a Nigerian address only', async ({ page }) => {
  const field = page.locator('#shipping-gatepost-postcode');
  await page.locator('#shipping-country').selectOption('NG');
  await expect(field).toBeVisible();
  await expect(page.locator('#shipping-postcode')).toHaveCount(0);

  await page.locator('#shipping-country').selectOption('GB');
  await expect(field).toHaveCount(0);
  await expect(page.locator('#shipping-postcode')).toBeVisible();
});

test('saves a checked postcode in canonical form', async ({ page }) => {
  await fillAddress(page, 'fc 01 z99 zz 01');
  await page.getByRole('button', { name: 'Place Order' }).click();
  const orderId = await orderIdAfterPlacing(page);
  expect(orderMeta(orderId, '_gatepost_shipping_postcode')).toBe('FC-01-Z99-ZZ-01');
  expect(orderMeta(orderId, '_gatepost_postcode_check')).toBe('valid');
  expect(orderAddressPostcode(orderId, 'shipping')).toBe('FC-01-Z99-ZZ-01');
});

test('names the problem and offers a corrected postcode', async ({ page }) => {
  await fillAddress(page, 'FCO1Z99ZZ01');
  await page.getByRole('button', { name: 'Place Order' }).click();
  await expect(
    page.getByText('Part of the postcode is not valid. Did you mean FC-01-Z99-ZZ-01?').first(),
  ).toBeVisible();
  await expect(page).not.toHaveURL(/order-received/);
});

test('places the order when the gateway does not answer', async ({ page }) => {
  useSettings({ secretKey: 'nipost_live_example', gateway: 'no-response' });
  await fillAddress(page, 'FC-01-Z99-ZZ-01');
  await page.getByRole('button', { name: 'Place Order' }).click();
  const orderId = await orderIdAfterPlacing(page);
  expect(orderMeta(orderId, '_gatepost_postcode_check')).toBe('error');
  expect(orderMeta(orderId, '_gatepost_shipping_postcode')).toBe('FC-01-Z99-ZZ-01');
  expect(orderNotes(orderId)).toContain('was not checked');
});

test('accepts an old postcode by default and does not look it up', async ({ page }) => {
  await fillAddress(page, '900108');
  await page.getByRole('button', { name: 'Place Order' }).click();
  const orderId = await orderIdAfterPlacing(page);
  expect(orderMeta(orderId, '_gatepost_shipping_postcode')).toBe('900108');
  expect(orderMeta(orderId, '_gatepost_postcode_check')).toBe('unchecked');
});

test('lists the postcode of a block order in the orders list', async ({ page }) => {
  useOrderTables(true);
  await fillAddress(page, 'FC-01-Z99-ZZ-01');
  await page.getByRole('button', { name: 'Place Order' }).click();
  const orderId = await orderIdAfterPlacing(page);
  await logIn(page);
  await page.goto('/wp-admin/admin.php?page=wc-orders');
  const cell = page.locator(`tr#order-${orderId} td.column-gatepost_postcode`);
  await expect(cell).toHaveText('FC-01-Z99-ZZ-01Checked');
});

test('asks for the new postcode when the store rejects old ones', async ({ page }) => {
  useSettings({ legacy: 'reject' });
  await fillAddress(page, '900108');
  await page.getByRole('button', { name: 'Place Order' }).click();
  await expect(page.getByText('This is an old 6-digit postcode.').first()).toBeVisible();
  await expect(page).not.toHaveURL(/order-received/);
});

test('reaches the field by keyboard and passes an accessibility check', async ({ page }) => {
  await page.locator('#shipping-country').selectOption('NG');
  await page.locator('#shipping-city').focus();
  const field = page.locator('#shipping-gatepost-postcode');
  const focused = () => field.evaluate((input) => input === document.activeElement);
  for (let step = 0; step < 6 && !(await focused()); step++) {
    await page.keyboard.press('Tab');
  }
  await expect(field).toBeFocused();
  await page.keyboard.type('FC 01 Z99 ZZ 01');
  await expect(field).toHaveValue('FC 01 Z99 ZZ 01');

  const scan = await new AxeBuilder({ page })
    .include('.wc-block-components-address-form__gatepost-postcode')
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    .analyze();
  expect(scan.violations).toEqual([]);
});

test('lets a customer outside Nigeria pay when the store requires the postcode', async ({
  page,
}) => {
  useSettings({ required: 'yes' });
  await page.reload();
  await page.locator('#email').fill('ada@example.com');
  await page.locator('#shipping-country').selectOption('GB');
  await page.locator('#shipping-first_name').fill('Ada');
  await page.locator('#shipping-last_name').fill('Obi');
  await page.locator('#shipping-address_1').fill('1 Test Road');
  await page.locator('#shipping-city').fill('London');
  // The checkout block saves a changed address first, and ignores a click until it has done so.
  const saved = page.waitForResponse((response) => response.url().includes('/wc/store/v1/batch'));
  await page.locator('#shipping-postcode').fill('SW1A 1AA');
  await page.locator('#shipping-postcode').blur();
  await saved;
  await page.getByRole('button', { name: 'Place Order' }).click();
  const orderId = await orderIdAfterPlacing(page);
  expect(orderMeta(orderId, '_gatepost_postcode_check')).toBe('');
});
