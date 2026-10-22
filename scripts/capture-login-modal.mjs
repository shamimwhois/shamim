// Assertion-based verification of the guest-login-modal + post-login workflow
// across desktop / tablet / mobile, the error state, the protected bounce
// auto-open, and a successful login landing on the role dashboard.
//
// Unlike a pure screenshot pass, every scene asserts the expected state with
// Playwright locators and the script exits non-zero if any assertion fails, so
// it is safe to wire into CI (or run locally) wherever a browser is available:
//
//   1. npx playwright install chromium
//   2. php artisan serve --port=8000   (a separate terminal)
//   3. BASE_URL=http://127.0.0.1:8000 node scripts/capture-login-modal.mjs
//
// Screenshots still land in /screenshots/login-modal for visual inspection.

import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE_URL = process.env.BASE_URL ?? 'http://127.0.0.1:8000';
const OUT = new URL('../screenshots/login-modal/', import.meta.url).pathname;
mkdirSync(OUT, { recursive: true });

// Unique account so repeated runs never hit "email already taken".
const email = `modal-check-${Date.now()}@example.com`;
const password = 'Password123!';

const checks = [];
const check = (name, ok, detail = '') => {
  checks.push({ name, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
};
const snap = async (page, name) => {
  const file = `${OUT}${name}.png`;
  await page.screenshot({ path: file, fullPage: false });
};

const run = async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext();
  const page = await ctx.newPage();

  page.on('pageerror', (err) => check('no uncaught page errors', false, err.message));

  // ---- 1. Desktop: modal is mounted but closed, then opens on trigger ----
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(BASE_URL, { waitUntil: 'networkidle' });
  check('desktop home loads', (await page.locator('body').count()) > 0);

  const emailInput = page.locator('[data-test="login-modal-email"]');
  check('modal hidden before trigger', !(await emailInput.isVisible().catch(() => false)));

  await page.click('[data-test="sign-in-trigger"]');
  await emailInput.waitFor({ state: 'visible', timeout: 5000 });
  check('modal opens via Sign-in trigger', await emailInput.isVisible());
  await snap(page, '02-desktop-modal-open');

  // ---- 2. Error state: empty submit must reopen the dialog (rejection) ----
  await page.click('[data-test="login-modal-submit"]');
  // The form stays put (no navigation to a dashboard) and reopens on $errors.
  await page.waitForFunction(
    () => !window.location.pathname.startsWith('/dashboard'),
    { timeout: 5000 },
  ).catch(() => {});
  const stillOnHome = !page.url().includes('/dashboard');
  check('bad submit keeps guest on home (no silent success)', stillOnHome, page.url());
  check('modal reopens on validation error', await emailInput.isVisible());
  // Either Laravel's required-message or its credentials message should show.
  const errorShown = await page
    .getByText(/required|credentials do not match|field is required/i)
    .count();
  check('validation error message is visible', errorShown > 0);
  await snap(page, '03-desktop-modal-error');

  await page.keyboard.press('Escape');

  // ---- 3. Protected bounce auto-opens the modal ----
  await page.goto(`${BASE_URL}/dashboard`, { waitUntil: 'networkidle' });
  // /dashboard bounces guests to /?signin=required, which opens the modal.
  const redirectedToSignIn = page.url().includes('signin=required');
  check('protected route bounces with signin flag', redirectedToSignIn, page.url());
  await emailInput.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
  check('bounce auto-opens the modal', await emailInput.isVisible());
  await snap(page, '04-bounce-auto-open');

  // ---- 4. Tablet ----
  await page.setViewportSize({ width: 768, height: 1024 });
  await page.goto(BASE_URL, { waitUntil: 'networkidle' });
  await page.click('[data-test="sign-in-trigger"]');
  await emailInput.waitFor({ state: 'visible', timeout: 5000 });
  check('tablet: modal opens', await emailInput.isVisible());
  await snap(page, '05-tablet-modal-open');
  await page.keyboard.press('Escape');

  // ---- 5. Mobile: disclosure nav, then modal ----
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(BASE_URL, { waitUntil: 'networkidle' });
  await page.locator('button[aria-controls="mobile-nav"]').click();
  await page.waitForSelector('#mobile-nav', { state: 'visible' });
  await page.click('[data-test="sign-in-trigger-mobile"]');
  await emailInput.waitFor({ state: 'visible', timeout: 5000 });
  check('mobile: modal opens from disclosure nav', await emailInput.isVisible());
  await snap(page, '06-mobile-modal-open');
  await page.keyboard.press('Escape');

  // ---- 6. Successful login lands on the role dashboard ----
  await page.setViewportSize({ width: 1440, height: 900 });
  // Register a real account — the form is real, and the response redirects to
  // the role dashboard via LoginResponse -> role dashboard.
  await page.goto(`${BASE_URL}/register`, { waitUntil: 'networkidle' });
  if (await page.locator('input[type="email"]').count()) {
    await page.fill('input[type="email"]', email);
    await page.fill('input[name="password"]', password);
    await page.fill('input[name="password_confirmation"]', password).catch(() => {});
    await page.click('button[type="submit"]');
    await page.waitForFunction(
      () => window.location.href.includes('dashboard'),
      undefined,
      { timeout: 8000 },
    ).catch(() => {});
    check('post-register lands on dashboard', page.url().includes('dashboard'), page.url());
    check('authenticated: sign-in trigger gone', (await page.locator('[data-test="sign-in-trigger"]').count()) === 0);
    await snap(page, '07-post-login-dashboard');
  } else {
    // No public register surface — fall back to the modal login with supplied
    // credentials (set TEST_EMAIL / TEST_PASSWORD env if you use this path).
    const testEmail = process.env.TEST_EMAIL ?? email;
    const testPassword = process.env.TEST_PASSWORD ?? password;
    await page.goto(BASE_URL, { waitUntil: 'networkidle' });
    await page.click('[data-test="sign-in-trigger"]');
    await emailInput.waitFor({ state: 'visible', timeout: 5000 });
    await page.fill('[data-test="login-modal-email"]', testEmail);
    await page.fill('[data-test="login-modal-password"]', testPassword);
    await page.click('[data-test="login-modal-submit"]');
    await page.waitForFunction(
      () => window.location.href.includes('dashboard'),
      undefined,
      { timeout: 8000 },
    ).catch(() => {});
    check('post-login lands on dashboard', page.url().includes('dashboard'), page.url());
    await snap(page, '07-post-login-dashboard');
  }

  await browser.close();

  const failed = checks.filter((c) => !c.ok);
  console.log(`\n${checks.length - failed.length}/${checks.length} checks passed.`);
  if (failed.length) {
    console.error('FAILED CHECKS:', failed.map((c) => c.name).join(', '));
    process.exit(1);
  }
};

run().catch((err) => {
  console.error(err);
  process.exit(1);
});
