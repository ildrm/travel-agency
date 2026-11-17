const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
for (const lang of ['en', 'fa']) {
  test(`public discovery, keyboard, RTL and mobile reflow: ${lang}`, async ({ page }) => {
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto('/?lang=' + lang, { waitUntil: 'networkidle' });
    await expect(page.locator('html')).toHaveAttribute('lang', lang);
    await expect(page.locator('html')).toHaveAttribute('dir', lang === 'fa' ? 'rtl' : 'ltr');
    await expect(page.locator('#atlas-home-title')).toBeVisible();
    await expect(page.locator('#atlas-discover')).toBeAttached();
    await expect(page.locator('body')).not.toContainText('[atlas_');
    await page.keyboard.press('Tab');
    await expect(page.locator('.atlas-skip')).toBeFocused();
    await page.screenshot({ path: `implementation/evidence/home-${lang}-desktop.png`, fullPage: true, animations: 'disabled' });
    await page.setViewportSize({ width: 320, height: 800 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBeTruthy();
    await page.screenshot({ path: `implementation/evidence/home-${lang}-mobile.png`, fullPage: true, animations: 'disabled' });
    expect(errors).toEqual([]);
  });
  test(`axe public discovery: ${lang}`, async ({ page }) => {
    await page.goto('/?lang=' + lang, { waitUntil: 'networkidle' });
    const report = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    fs.writeFileSync(path.join(root, `implementation/evidence/axe-${lang}.json`), JSON.stringify(report, null, 2));
    expect(report.violations.filter(v => ['critical', 'serious'].includes(v.impact)), JSON.stringify(report.violations)).toEqual([]);
  });
}
test('authentication, customer account and scoped partner portal', async ({ page }) => {
  const credentials = JSON.parse(fs.readFileSync(path.join(root, '.runtime/credentials.json')));
  await page.goto('/wp-login.php');
  // WordPress schedules its initial username focus after page load; wait for that
  // actual focus rather than race it while filling the password.
  await expect(page.locator('#user_login')).toBeFocused();
  await page.locator('#user_login').fill(credentials.admin_user);
  await page.locator('#user_pass').fill(credentials.admin_password);
  await expect(page.locator('#user_login')).toHaveValue(credentials.admin_user);
  await expect(page.locator('#user_pass')).toHaveValue(credentials.admin_password);
  await page.locator('#wp-submit').click();
  await page.waitForURL(/wp-admin/);
  await page.goto('/?atlas_area=account&lang=fa', { waitUntil: 'networkidle' });
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await expect(page.locator('body')).not.toContainText('[atlas_account]');
  await expect(page.locator('h1').last()).toContainText('حساب');
  await page.screenshot({ path: 'implementation/evidence/account-fa.png', fullPage: true, animations: 'disabled' });
  await page.goto('/?atlas_area=portal&lang=en', { waitUntil: 'networkidle' });
  await expect(page.locator('body')).not.toContainText('[atlas_portal]');
  await expect(page.locator('h1').last()).toContainText('Partner');
});
test('public APIs reject forced crypto and unauthenticated private access', async ({ request }) => {
  const catalog = await request.get('/wp-json/agency/v1/catalog');
  expect(catalog.status()).toBe(200);
  const privateRecord = await request.get('/wp-json/agency/v1/bookings/not-a-booking');
  expect([401, 403, 404]).toContain(privateRecord.status());
  const crypto = await request.post('/wp-json/agency/v1/crypto', { data: { enabled: true, asset: 'BTC' } });
  expect(crypto.status()).toBeGreaterThanOrEqual(400);
});
