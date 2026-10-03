// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { wp } from './shop';

const zip = 'dist/gatepost-postcode-for-woocommerce.zip';
const installed = 'build/e2e/wp-content/plugins/gatepost-postcode-for-woocommerce';

test('the zip holds the plugin and the prefixed SDK, and no dev file', () => {
  const entries = execFileSync('unzip', ['-Z1', zip], { encoding: 'utf8' }).split('\n');
  const top = new Set(
    entries.map((entry) => entry.split('/').slice(1, 2).join('')).filter((name) => name !== ''),
  );
  expect([...top].sort()).toEqual([
    'LICENSE',
    'LICENSES',
    'NOTICE',
    'gatepost-postcode-for-woocommerce.php',
    'readme.txt',
    'src',
    'uninstall.php',
    'vendor-prefixed',
  ]);
});

test('the installed zip checks a format with the prefixed SDK alone', () => {
  expect(existsSync(`${installed}/vendor`)).toBe(false);
  expect(existsSync(`${installed}/vendor-prefixed/autoload.php`)).toBe(true);
  const report = wp(
    'eval',
    `$prefixed = 'Gatepost\\\\WooCommerce\\\\Vendor\\\\Gatepost\\\\Postcode\\\\Postcode';
    echo json_encode( array(
      'unprefixed' => class_exists( 'Gatepost\\\\Postcode\\\\Postcode', false ),
      'prefixed'   => class_exists( $prefixed ),
      'problem'    => Gatepost\\WooCommerce\\Plugin::checker()->format_problem( 'FCO1Z99ZZ01' ),
      'stored'     => Gatepost\\WooCommerce\\Plugin::checker()->stored_form( 'fc 01 z99 zz 01' ),
    ) );`,
  );
  expect(JSON.parse(report)).toEqual({
    unprefixed: false,
    prefixed: true,
    problem: 'Part of the postcode is not valid. Did you mean FC-01-Z99-ZZ-01?',
    stored: 'FC-01-Z99-ZZ-01',
  });
});

test('the shop refuses a request to any host but the fake gateway', () => {
  wp('option', 'delete', 'gatepost_e2e_blocked');
  const answer = wp(
    'eval',
    "echo is_wp_error( wp_remote_get( 'https://elsewhere.invalid/' ) ) ? 'refused' : 'sent';",
  );
  expect(answer).toBe('refused');
  expect(wp('option', 'get', 'gatepost_e2e_blocked', '--format=json')).toContain(
    'elsewhere.invalid',
  );
  wp('option', 'delete', 'gatepost_e2e_blocked');
});
