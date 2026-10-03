// SPDX-FileCopyrightText: 2026 The Gatepost authors
// SPDX-License-Identifier: Apache-2.0
import { defineConfig } from '@playwright/test';

// The tests share one shop and switch its settings, so they run one at a time.
export default defineConfig({
  testDir: 'tests/e2e',
  workers: 1,
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  timeout: 60_000,
  expect: { timeout: 15_000 },
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    baseURL: 'http://127.0.0.1:8890',
    trace: 'retain-on-failure',
  },
  webServer: {
    // Four workers, because the checkout block loads its scripts in parallel.
    command: 'PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8890 -t build/e2e',
    url: 'http://127.0.0.1:8890/wp-login.php',
    reuseExistingServer: false,
    timeout: 30_000,
    // PHP logs every request. A failed test keeps its trace in test-results/.
    stderr: 'ignore',
  },
});
