const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
async function signIn(page, credentials, role, destination) {
  await page.goto('/wp-login.php?redirect_to=' + encodeURIComponent('http://127.0.0.1:8840/' + destination));
  await expect(page.locator('#user_login')).toBeFocused();
  await page.locator('#user_login').fill(credentials[role + '_user']);
  await page.locator('#user_pass').fill(credentials[role + '_password']);
  // Assert without printing generated passwords in a failure report.
  expect(await page.locator('#user_pass').inputValue() === credentials[role + '_password']).toBe(true);
  await page.locator('#wp-submit').click();
  await page.waitForURL(url => url.pathname !== '/wp-login.php');
}
for (const lang of ['en', 'fa']) {
  test(`public discovery, keyboard, RTL and mobile reflow: ${lang}`, async ({ page }) => {
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.addInitScript(() => {
      window.atlasMeasuredLCP = null; window.atlasMeasuredCLS = 0;
      new PerformanceObserver(list => {
        for (const entry of list.getEntries()) window.atlasMeasuredLCP = entry.startTime;
      }).observe({ type: 'largest-contentful-paint', buffered: true });
      new PerformanceObserver(list => {
        for (const entry of list.getEntries()) if (!entry.hadRecentInput) window.atlasMeasuredCLS += entry.value;
      }).observe({ type: 'layout-shift', buffered: true });
    });
    await page.goto('/?lang=' + lang, { waitUntil: 'networkidle' });
    await expect(page.locator('html')).toHaveAttribute('lang', lang);
    await expect(page.locator('html')).toHaveAttribute('dir', lang === 'fa' ? 'rtl' : 'ltr');
    await expect(page.locator('#atlas-home-title')).toBeVisible();
    await expect(page.locator('#atlas-discover')).toBeAttached();
    await expect(page.locator('body')).not.toContainText('[atlas_');
    const metrics = await page.evaluate(() => ({
      navigation_ms: performance.getEntriesByType('navigation')[0]?.duration,
      lcp_ms: window.atlasMeasuredLCP, cls: window.atlasMeasuredCLS,
      resources: performance.getEntriesByType('resource').length,
      encoded_resource_bytes: performance.getEntriesByType('resource').reduce((sum, item) => sum + item.encodedBodySize, 0)
    }));
    fs.writeFileSync(path.join(root, `implementation/evidence/performance-${lang}.json`), JSON.stringify({
      scope: 'one local headless desktop navigation; not field p75, load capacity, INP or a production benchmark', ...metrics
    }, null, 2));
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
  await page.goto('/wp-login.php?redirect_to=' + encodeURIComponent('http://127.0.0.1:8840/?atlas_area=account&lang=fa'));
  // WordPress schedules its initial username focus after page load; wait for that
  // actual focus rather than race it while filling the password.
  await expect(page.locator('#user_login')).toBeFocused();
  await page.locator('#user_login').fill(credentials.admin_user);
  await page.locator('#user_pass').fill(credentials.admin_password);
  await expect(page.locator('#user_login')).toHaveValue(credentials.admin_user);
  expect(await page.locator('#user_pass').inputValue() === credentials.admin_password).toBe(true);
  await page.locator('#wp-submit').click();
  await page.waitForURL(url => url.searchParams.get('atlas_area') === 'account');
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

async function chooseAddressOption(page, selector, value, label) {
  const field = page.locator(selector);
  await expect(field).toBeVisible();
  const tag = await field.evaluate(el => el.tagName);
  if (tag === 'SELECT') {
    await field.selectOption(value);
  } else {
    await field.fill(label);
    await page.getByRole('option', { name: label, exact: true }).click();
  }
}

for (const lang of ['en', 'fa']) {
  test(`customer reserve -> Woo Blocks simulated payment -> agency confirmation: ${lang}`, async ({ page, browser }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const credentials = JSON.parse(fs.readFileSync(path.join(root, '.runtime/credentials.json')));
    const fixtures = JSON.parse(fs.readFileSync(path.join(root, '.runtime/browser-fixtures.json')));
    expect(Boolean(credentials.customer_user && fixtures.event)).toBe(true);
    await signIn(page, credentials, 'customer', '?atlas_area=account&lang=' + lang);
    await page.goto('/?offer_id=' + fixtures.event + '&lang=' + lang, { waitUntil: 'networkidle' });
    const form = page.locator('form[data-atlas-booking]');
    await expect(form).toBeVisible();
    const restNonce = await page.evaluate(() => window.atlasBooking.restNonce);
    // A dedicated fictional customer's persisted Woo cart can survive a failed
    // previous run. Reset only that customer's cart as a test precondition.
    const storeNonce = await page.evaluate(() => window.atlasBooking.storeNonce);
    const reset = await page.request.delete('/wp-json/wc/store/v1/cart/items', { headers: { Nonce: storeNonce, 'X-WP-Nonce': restNonce } });
    expect(reset.status()).toBe(200);
    const holdResponse = page.waitForResponse(r => r.url().includes('/agency/v1/holds') && r.request().method() === 'POST');
    await form.locator('input[name="quantity"]').fill('1');
    await form.locator('button[type="submit"]').click();
    const hold = await holdResponse;
    expect(hold.status()).toBe(201);
    const booking = await hold.json();
    await page.waitForURL(url => url.pathname.includes('/checkout'));
    await expect(page.locator('html')).toHaveAttribute('lang', lang);
    const email = page.locator('#email');
    if (await email.count()) await email.fill('customer@example.test');
    const editAddress = page.getByRole('button', { name: /Edit billing address|ویرایش آدرس صورتحساب/ });
    if (await editAddress.isVisible()) await editAddress.click();
    await chooseAddressOption(page, '#billing-country', 'IR', lang === 'fa' ? 'ایران' : 'Iran');
    await page.locator('#billing-first_name').fill(lang === 'fa' ? 'کاربر' : 'Fictional');
    await page.locator('#billing-last_name').fill(lang === 'fa' ? 'آزمایشی' : 'Customer');
    await page.locator('#billing-address_1').fill(lang === 'fa' ? 'نشانی فرضی ۱' : '1 Fictional Road');
    await page.locator('#billing-city').fill(lang === 'fa' ? 'تهران' : 'Tehran');
    await chooseAddressOption(page, '#billing-state', 'THR', 'تهران');
    await page.locator('#billing-postcode').fill('1111111111');
    const phone = page.locator('#billing-phone');
    if (await phone.count()) await phone.fill('+12025550123'); // Reserved fictional NANP number.
    const method = page.locator('input[value="atlas_simulator"]');
    await expect(method).toBeAttached();
    if (!(await method.isChecked())) {
      await page.locator('label[for="' + await method.getAttribute('id') + '"]').click();
    }
    const terms = page.locator('.wc-block-checkout__terms input[type="checkbox"]');
    for (const input of await terms.all()) await input.check();
    await page.screenshot({ path: `implementation/evidence/checkout-${lang}.png`, fullPage: true, animations: 'disabled' });
    const checkoutAxe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    fs.writeFileSync(path.join(root, `implementation/evidence/axe-checkout-${lang}.json`), JSON.stringify(checkoutAxe, null, 2));
    expect(checkoutAxe.violations.filter(v => ['critical', 'serious'].includes(v.impact)),
      JSON.stringify(checkoutAxe.violations.map(v => ({ id: v.id, impact: v.impact, targets: v.nodes.map(n => n.target) })))).toEqual([]);
    const checkoutResponse = page.waitForResponse(r => r.url().includes('/wc/store/v1/checkout') && r.request().method() === 'POST');
    await page.locator('.wc-block-components-checkout-place-order-button').click();
    const checkout = await checkoutResponse;
    const result = await checkout.json();
    expect(checkout.ok(), result.message || 'Woo checkout must succeed').toBe(true);
    await page.waitForURL(url => url.pathname.includes('order-received'));
    await expect(page.locator('html')).toHaveAttribute('lang', lang);
    await expect(page.locator('[data-atlas-confirmation-status="paid_pending_confirmation"]').first()).toBeVisible();
    const state = await page.request.get('/wp-json/agency/v1/bookings/' + booking.booking_id,
      { headers: { 'X-WP-Nonce': restNonce } });
    expect(state.status()).toBe(200);
    const record = await state.json();
    expect(record.payment_status).toBe('verified');
    expect(record.status).toBe('paid_pending_confirmation');
    await page.screenshot({ path: `implementation/evidence/receipt-${lang}.png`, fullPage: true, animations: 'disabled' });
    // Opaque IDs only; no order keys, nonces, billing fields or credentials enter evidence.
    fs.writeFileSync(path.join(root, `.runtime/browser-order-${lang}.json`),
      JSON.stringify({ booking_id: booking.booking_id, order_id: result.order_id, status: record.status }));
    const staffContext = await browser.newContext({ baseURL: 'http://127.0.0.1:8840', reducedMotion: 'reduce', viewport: { width: 1440, height: 1000 } });
    try {
      const staff = await staffContext.newPage();
      await signIn(staff, credentials, 'admin', '?atlas_area=portal&lang=' + lang);
      const queue = staff.locator('[data-atlas-booking-queue]');
      await queue.locator('xpath=..').locator('summary').click();
      let row = queue.locator('tbody tr').filter({ hasText: booking.booking_id });
      for (let index = 0; index < 10; index++) {
        await expect(queue.locator('[role="status"]')).not.toContainText(/Loading reservations|در حال دریافت/);
        if (await row.count()) break;
        const next = queue.locator('[data-next-page]');
        expect(await next.isEnabled(), 'The operator must be able to find the new booking').toBe(true);
        await next.click();
      }
      await expect(row).toBeVisible();
      await row.locator('input[name="reason"]').fill('Fictional local service checked for automated demonstration.');
      const confirmedResponse = staff.waitForResponse(r => r.url().includes('/bookings/' + booking.booking_id + '/confirm') && r.request().method() === 'POST');
      await row.locator('button[type="submit"]').click();
      expect((await confirmedResponse).ok()).toBe(true);
      await expect(row).toContainText('confirmed');
      const confirmed = await page.request.get('/wp-json/agency/v1/bookings/' + booking.booking_id,
        { headers: { 'X-WP-Nonce': restNonce } });
      expect((await confirmed.json()).status).toBe('confirmed');
      await staff.screenshot({ path: `implementation/evidence/agency-confirm-${lang}.png`, fullPage: true, animations: 'disabled' });
      expect(errors).toEqual([]);
    } finally {
      await staffContext.close();
    }
  });
}
