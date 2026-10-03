// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';
import {
  fillBook,
  orderAddressPostcode,
  orderIdAfterPlacing,
  orderMeta,
  orderNotes,
  test,
  useCheckout,
  useSettings,
} from './shop';

async function fillAddress(page: Page, postcode: string): Promise<void> {
  await page.locator('#billing_country').selectOption('NG');
  await page.locator('#billing_first_name').fill('Ada');
  await page.locator('#billing_last_name').fill('Obi');
  await page.locator('#billing_address_1').fill('1 Test Road');
  await page.locator('#billing_city').fill('Abuja');
  await page.locator('#billing_state').selectOption('FC');
  await page.locator('#billing_phone').fill('08000000000');
  await page.locator('#billing_email').fill('ada@example.com');
  await page.locator('#billing_gatepost_postcode').fill(postcode);
}

test.beforeEach(async ({ page }) => {
  useSettings({ secretKey: 'nipost_live_example' });
  await fillBook(page, useCheckout('classic'));
});

test('shows the postcode field for a Nigerian address only', async ({ page }) => {
  const field = page.locator('#billing_gatepost_postcode');
  await page.locator('#billing_country').selectOption('NG');
  await expect(field).toBeVisible();
  await expect(page.locator('#billing_postcode')).toBeHidden();

  await page.locator('#billing_country').selectOption('GB');
  await expect(field).toBeHidden();
  await expect(page.locator('#billing_postcode')).toBeVisible();
});

test('saves a checked postcode in canonical form and in the address', async ({ page }) => {
  await fillAddress(page, 'fc01z99zz01');
  await page.locator('#place_order').click();
  const orderId = await orderIdAfterPlacing(page);
  expect(orderMeta(orderId, '_gatepost_billing_postcode')).toBe('FC-01-Z99-ZZ-01');
  expect(orderMeta(orderId, '_gatepost_postcode_check')).toBe('valid');
  expect(orderMeta(orderId, '_billing_gatepost_postcode')).toBe('');
  expect(orderAddressPostcode(orderId, 'billing')).toBe('FC-01-Z99-ZZ-01');
});

test('places the order when the gateway does not answer', async ({ page }) => {
  useSettings({ secretKey: 'nipost_live_example', gateway: 'no-response' });
  await fillAddress(page, 'FC-01-Z99-ZZ-01');
  await page.locator('#place_order').click();
  const orderId = await orderIdAfterPlacing(page);
  expect(orderMeta(orderId, '_gatepost_postcode_check')).toBe('error');
  expect(orderAddressPostcode(orderId, 'billing')).toBe('FC-01-Z99-ZZ-01');
  expect(orderNotes(orderId)).toContain('was not checked');
});

test('lets a customer outside Nigeria pay with a British postcode', async ({ page }) => {
  useSettings({ required: 'yes' });
  await page.reload();
  await page.locator('#billing_country').selectOption('GB');
  await page.locator('#billing_first_name').fill('Ada');
  await page.locator('#billing_last_name').fill('Obi');
  await page.locator('#billing_address_1').fill('1 Test Road');
  await page.locator('#billing_city').fill('London');
  await page.locator('#billing_postcode').fill('SW1A 1AA');
  await page.locator('#billing_phone').fill('08000000000');
  await page.locator('#billing_email').fill('ada@example.com');
  await page.locator('#place_order').click();
  const orderId = await orderIdAfterPlacing(page);
  expect(orderMeta(orderId, '_gatepost_postcode_check')).toBe('');
  expect(orderAddressPostcode(orderId, 'billing')).toBe('SW1A 1AA');
});

test('names the problem and offers a corrected postcode', async ({ page }) => {
  await fillAddress(page, 'EKO1A03FK01');
  await page.locator('#place_order').click();
  await expect(
    page.locator('.woocommerce-error, .woocommerce-NoticeGroup').getByText(
      'Part of the postcode is not valid. Did you mean EK-01-A03-FK-01?',
    ),
  ).toBeVisible();
  await expect(page).not.toHaveURL(/order-received/);
});

test('accepts an old postcode by default', async ({ page }) => {
  await fillAddress(page, '900108');
  await page.locator('#place_order').click();
  const orderId = await orderIdAfterPlacing(page);
  expect(orderMeta(orderId, '_gatepost_billing_postcode')).toBe('900108');
  expect(orderMeta(orderId, '_gatepost_postcode_check')).toBe('unchecked');
});

test('asks for the new postcode when the store rejects old ones', async ({ page }) => {
  useSettings({ legacy: 'reject' });
  await fillAddress(page, '900108');
  await page.locator('#place_order').click();
  await expect(page.getByText('This is an old 6-digit postcode.').first()).toBeVisible();
  await expect(page).not.toHaveURL(/order-received/);
});

test('marks the field required when the store asks for it', async ({ page }) => {
  useSettings({ required: 'yes' });
  await page.reload();
  await page.locator('#billing_country').selectOption('NG');
  await expect(page.locator('#billing_gatepost_postcode_field .required')).toBeVisible();
  const scan = await new AxeBuilder({ page })
    .include('#billing_gatepost_postcode_field')
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
    .analyze();
  expect(scan.violations).toEqual([]);
});
