import { expect, test } from '@playwright/test';

test('docs page renders, search works, health and a keyed call succeed', async ({ page, request }) => {
  const errors: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  // The bundle is self-hosted: the page must never reach another origin (CDN, fonts, telemetry).
  const origin = new URL(process.env.VD_BASE_URL ?? 'http://localhost:8087').origin;
  const external: string[] = [];
  page.on('request', (r) => { if (!r.url().startsWith(origin) && !r.url().startsWith('data:')) external.push(r.url()); });

  // The self-hosted bundle and the contract must be reachable before the page can render them.
  expect((await request.get('/vendor/scalar/standalone.js')).status()).toBe(200);
  expect((await request.get('/openapi.yaml')).status()).toBe(200);

  const failed: string[] = [];
  page.on('response', (r) => { if (r.status() >= 400) failed.push(`${r.status()} ${r.url()}`); });

  await page.goto('/docs');
  await expect(page).toHaveTitle(/vehicle-data-api/);
  // The contract is fetched from /openapi.yaml and rendered: title, tag groups and an operation summary.
  await expect(page.getByRole('heading', { name: 'vehicle-data-api', level: 1 }), `console errors: ${errors.join(' | ')}; failed responses: ${failed.join(' | ')}`).toBeVisible({ timeout: 30_000 });
  await expect(page.getByRole('link', { name: 'catalogue', exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: /Readiness \(keyed\)/ })).toBeVisible();

  await page.getByRole('button', { name: /Open Search/ }).click();
  await page.getByRole('combobox', { name: /search query/i }).fill('taxonom');
  await expect(page.getByRole('option', { name: /\/v1\/taxonomies\/\{key\}/ })).toBeVisible();

  expect(errors.filter((e) => e.includes('Content Security Policy'))).toEqual([]);
  expect(external).toEqual([]);

  const health = await request.get('/v1/health');
  expect(health.status()).toBe(200);
  expect((await health.json()).data.status).toBe('ok');

  const key = process.env.VD_TEST_KEY;
  test.skip(!key, 'VD_TEST_KEY not set');
  const keyed = await request.get('/v1/taxonomies/fuel', { headers: { Authorization: `Bearer ${key}` } });
  expect(keyed.status()).toBe(200);
  expect(JSON.stringify(await keyed.json())).toContain('Benzină');

  const en = await request.get('/v1/taxonomies/national_category?lang=en', { headers: { Authorization: `Bearer ${key}` } });
  expect((await en.json()).data.terms[0].id).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);
});
