import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: 'tests/Browser',
  // Generous: PHP in the local Docker stack can take several seconds per request.
  timeout: 90_000,
  expect: { timeout: 20_000 },
  use: { baseURL: process.env.VD_BASE_URL ?? 'http://localhost:8087', trace: 'retain-on-failure' },
  reporter: [['list'], ['html', { open: 'never' }]],
});
