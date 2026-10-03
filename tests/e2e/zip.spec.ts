// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0
import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { expect, test } from '@playwright/test';
import { wp } from './shop';

const zip = 'dist/gatepost-postcode-for-woocommerce.zip';
const installed = 'build/e2e/wp-content/plugins/gatepost-postcode-for-woocommerce';

// Lists the files that the zip may hold: the tracked files of the plugin, and the bundled SDK
// that Strauss writes to vendor-prefixed/. Any other file, such as a stray backup in src/, fails.
function expectedFiles(): string[] {
  const tracked = execFileSync(
    'git',
    ['ls-files', 'gatepost-postcode-for-woocommerce.php', 'uninstall.php', 'readme.txt', 'LICENSE',
      'NOTICE', 'LICENSES', 'src'],
    { encoding: 'utf8' },
  ).split('\n');
  const bundled = readdirSync('vendor-prefixed', { recursive: true, withFileTypes: true })
    .filter((entry) => entry.isFile())
    .map((entry) => join(entry.parentPath, entry.name));
  return [...tracked, ...bundled].filter((name) => name !== '').sort();
}

test('the zip holds the tracked plugin files and the prefixed SDK, and nothing else', () => {
  const entries = execFileSync('unzip', ['-Z1', zip], { encoding: 'utf8' })
    .split('\n')
    .filter((entry) => entry !== '' && !entry.endsWith('/'))
    .map((entry) => entry.split('/').slice(1).join('/'))
    .sort();
  expect(entries).toEqual(expectedFiles());
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
