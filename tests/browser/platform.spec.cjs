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
    await expect(page.locator('.atlas-hero-search input[name=cat_destination]')).toBeVisible();
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
    await page.screenshot({ path: `implementation/evidence/home-${lang}-desktop.png`, fullPage: true, animations: 'disabled' });
    await page.setViewportSize({ width: 320, height: 800 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBeTruthy();
    await page.screenshot({ path: `implementation/evidence/home-${lang}-mobile.png`, fullPage: true, animations: 'disabled' });
    await page.keyboard.press('Tab');
    await expect(page.locator('.atlas-skip')).toBeFocused();
    const menu = page.locator('header .atlas-menu-toggle');
    await menu.click();
    await expect(menu).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('header .atlas-utility')).toBeVisible();
    await menu.press('Escape');
    await expect(menu).toBeFocused();
    await expect(page.locator('header .atlas-utility')).toBeHidden();
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
  const loginPage = await page.goto('/?atlas_area=account&lang=fa');
  expect(loginPage.headers()['cache-control']).toContain('no-store');
  expect(loginPage.headers()['x-robots-tag']).toContain('noindex');
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
  const jump = page.locator('.atlas-workspace-jump');
  await jump.locator('select').selectOption({label: 'Booking confirmation queue'});
  await jump.locator('button').click();
  await expect(page.locator('summary').filter({hasText: /^Booking confirmation queue$/})).toBeFocused();
});
test('public APIs reject forced crypto and unauthenticated private access', async ({ request }) => {
  const catalog = await request.get('/wp-json/agency/v1/catalog');
  expect(catalog.status()).toBe(200);
  const privateRecord = await request.get('/wp-json/agency/v1/bookings/not-a-booking');
  expect([401, 403, 404]).toContain(privateRecord.status());
  const crypto = await request.post('/wp-json/agency/v1/crypto', { data: { enabled: true, asset: 'BTC' } });
  expect(crypto.status()).toBeGreaterThanOrEqual(400);
});

test('catalog filters narrow results and keep the Farsi locale', async ({ page }) => {
  await page.goto('/?lang=fa');
  const destination = page.locator('#atlas-home-destination');
  await destination.fill('atlas-no-matching-city');
  await destination.press('Enter');
  await expect(page).toHaveURL(/cat_destination=atlas-no-matching-city.*#atlas-offers/);
  await expect(page.locator('[data-atlas-catalog]')).toContainText('پیشنهادی با این فیلترها یافت نشد');
  await page.locator('[data-catalog-filter] a').click();
  const filter = page.locator('form[data-catalog-filter]');
  await expect(filter).toBeVisible();
  await expect(filter.locator('details')).not.toHaveAttribute('open', '');
  await filter.locator('[name="cat_kind"]').selectOption('event');
  await filter.locator('button[type="submit"], button:not([type])').first().click();
  await expect(page).toHaveURL(/cat_kind=event/);
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
  await expect(page.locator('[data-atlas-catalog]')).toContainText('صبحی در طبیعت');
  await expect(page.locator('[data-atlas-catalog]')).not.toContainText('فرصتی برای آرامش');
});

test('reviewed guides link reciprocal published translations and canonical URLs', async ({ page }) => {
  const { guides } = JSON.parse(fs.readFileSync(path.join(root, '.runtime/browser-fixtures.json')));
  expect(Boolean(guides?.en && guides?.fa)).toBe(true);
  await page.goto(guides.en);
  await expect(page.getByRole('heading', { name: 'Paper atlas field notes' })).toBeVisible();
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', guides.en);
  await expect(page.locator('link[rel="alternate"][hreflang="fa"]')).toHaveAttribute('href', guides.fa);
  await page.locator('header .atlas-language a[lang="fa"]').click();
  await expect(page).toHaveURL(guides.fa);
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await expect(page.getByRole('heading', { name: 'یادداشت‌های اطلس کاغذی' })).toBeVisible();
  await expect(page.locator('link[rel="alternate"][hreflang="en"]')).toHaveAttribute('href', guides.en);
  await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', /راهنمای نمونه/);
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

test('physical product checkout calculates native shipping and keeps offline money pending', async ({ page }) => {
  const credentials = JSON.parse(fs.readFileSync(path.join(root, '.runtime/credentials.json')));
  const fixtures = JSON.parse(fs.readFileSync(path.join(root, '.runtime/browser-fixtures.json')));
  expect(Boolean(fixtures.merch_product && fixtures.merch_shipping_zone)).toBe(true);
  await signIn(page, credentials, 'customer', '?atlas_area=account&lang=en');
  const runFixture = JSON.parse(fs.readFileSync(path.join(root, '.runtime/resource-browser-fixtures.json')));
  await page.goto('/?offer_id=' + runFixture.event_offer_id + '&lang=en');
  const nonces = await page.evaluate(() => ({ Nonce: window.atlasBooking.storeNonce, 'X-WP-Nonce': window.atlasBooking.restNonce }));
  expect((await page.request.delete('/wp-json/wc/store/v1/cart/items', { headers: nonces })).status()).toBe(200);
  const product = await page.request.post('/wp-json/wc/store/v1/cart/add-item', { headers: nonces, data: { id: runFixture.merch_product_id, quantity: 1 } });
  expect(product.ok()).toBe(true);
  await page.goto('/checkout/?lang=en');
  const edit = page.getByRole('button', { name: /Edit shipping address/ });
  if (await edit.isVisible()) await edit.click();
  await page.locator('#email').fill('customer@example.test');
  await chooseAddressOption(page, '#shipping-country', 'IR', 'Iran');
  await page.locator('#shipping-first_name').fill('Fictional');
  await page.locator('#shipping-last_name').fill('Customer');
  await page.locator('#shipping-address_1').fill('1 Fictional Road');
  await page.locator('#shipping-city').fill('Tehran');
  await chooseAddressOption(page, '#shipping-state', 'THR', 'تهران');
  await page.locator('#shipping-postcode').fill('1111111111');
  const phone = page.locator('#shipping-phone');
  if (await phone.isVisible()) await phone.fill('+12025550123');
  await expect(page.getByText('Fictional flat-rate delivery', { exact: true }).first()).toBeVisible();
  // The fictional demo also supports simulation. This journey chooses Woo's native offline path.
  const method = page.locator('input[value="bacs"]');
  await expect(method).toBeAttached();
  if (!(await method.isChecked())) await page.locator('label[for="' + await method.getAttribute('id') + '"]').click();
  for (const input of await page.locator('.wc-block-checkout__terms input[type="checkbox"]').all()) await input.check();
  const response = page.waitForResponse(r => r.url().includes('/wc/store/v1/checkout') && r.request().method() === 'POST');
  await page.locator('.wc-block-components-checkout-place-order-button').click();
  const result = await response;
  expect(result.ok(), 'Physical checkout must succeed').toBe(true);
  await page.waitForURL(url => url.pathname.includes('order-received'));
  // Read the saved order after navigation. The checkout response body may have
  // already been discarded by the browser when the receipt loaded.
  const state = await page.evaluate(async headers => {
    const receipt = new URL(location.href);
    const orderId = receipt.pathname.match(/order-received\/(\d+)/)?.[1];
    const key = receipt.searchParams.get('key');
    if (!orderId || !key) return 'receipt-unavailable';
    const url = new URL('/wp-json/wc/store/v1/order/' + orderId, receipt.origin);
    url.searchParams.set('key', key);
    const saved = await fetch(url, {headers, credentials: 'same-origin'});
    return saved.ok ? (await saved.json()).status : 'order-unavailable';
  }, nonces);
  expect(state).toBe('on-hold');
  await expect(page.getByText(/Paper trail journal/).first()).toBeVisible();
  await expect(page.locator('[data-atlas-confirmation-status]')).toHaveCount(0);
});

for (const lang of ['en', 'fa']) {
  test(`customer reserve -> Woo Blocks simulated payment -> agency confirmation: ${lang}`, async ({ page, browser }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const credentials = JSON.parse(fs.readFileSync(path.join(root, '.runtime/credentials.json')));
    const fixtures = JSON.parse(fs.readFileSync(path.join(root, '.runtime/resource-browser-fixtures.json')));
    expect(Boolean(credentials.customer_user && fixtures.event_offer_id)).toBe(true);
    await signIn(page, credentials, 'customer', '?atlas_area=account&lang=' + lang);
    await page.goto('/?offer_id=' + fixtures.event_offer_id + '&lang=' + lang, { waitUntil: 'networkidle' });
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
    await expect(page.locator('main h1')).toHaveCount(1);
    for (const width of [320, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width + 1);
    }
    const summary = page.locator('.wc-block-components-order-summary');
    await expect(summary).toContainText(lang === 'fa' ? 'مهلت پرداخت' : 'Payment deadline');
    if (lang === 'fa') await expect(summary).toContainText('صبحی در طبیعت');
    await page.screenshot({ path: `implementation/evidence/checkout-${lang}.png`, fullPage: true, animations: 'disabled' });
    await expect(page.locator('#wc-guest-checkout-notice')).toHaveCount(1);
    await expect(page.locator('#email')).toHaveAttribute('aria-describedby', /wc-guest-checkout-notice/);
    const checkoutAxe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    fs.writeFileSync(path.join(root, `implementation/evidence/axe-checkout-${lang}.json`), JSON.stringify(checkoutAxe, null, 2));
    expect(checkoutAxe.violations.filter(v => ['critical', 'serious'].includes(v.impact)),
      JSON.stringify(checkoutAxe.violations.map(v => ({ id: v.id, impact: v.impact, targets: v.nodes.map(n => n.target) })))).toEqual([]);
    const checkoutResponse = page.waitForResponse(r => r.url().includes('/wc/store/v1/checkout') && r.request().method() === 'POST');
    await page.locator('.wc-block-components-checkout-place-order-button').click();
    const checkout = await checkoutResponse;
    expect(checkout.ok(), 'Woo checkout must succeed').toBe(true);
    await page.waitForURL(url => url.pathname.includes('order-received'));
    await expect(page.locator('html')).toHaveAttribute('lang', lang);
    await expect(page.locator('[data-atlas-confirmation-status="paid_pending_confirmation"]').first()).toBeVisible();
    await expect(page.locator('[data-atlas-voucher]')).toHaveCount(0);
    const state = await page.request.get('/wp-json/agency/v1/bookings/' + booking.booking_id,
      { headers: { 'X-WP-Nonce': restNonce } });
    expect(state.status()).toBe(200);
    const record = await state.json();
    expect(record.order_id).toBeGreaterThan(0);
    expect(new URL(page.url()).pathname).toContain('/order-received/' + record.order_id + '/');
    expect(record.payment_status).toBe('verified');
    expect(record.status).toBe('paid_pending_confirmation');
    await page.screenshot({ path: `implementation/evidence/receipt-${lang}.png`, fullPage: true, animations: 'disabled' });
    // Opaque IDs only; no order keys, nonces, billing fields or credentials enter evidence.
    fs.writeFileSync(path.join(root, `.runtime/browser-order-${lang}.json`),
      JSON.stringify({ booking_id: booking.booking_id, order_id: record.order_id, status: record.status }));
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
      await staff.setViewportSize({width: 320, height: 800});
      await expect.poll(() => staff.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(321);
      await staff.setViewportSize({width: 1440, height: 1000});
      await row.locator('input[name="reason"]').fill('Fictional local service checked for automated demonstration.');
      const confirmedResponse = staff.waitForResponse(r => r.url().includes('/bookings/' + booking.booking_id + '/confirm') && r.request().method() === 'POST');
      await row.locator('button[type="submit"]').click();
      expect((await confirmedResponse).ok()).toBe(true);
      await expect(row).toHaveAttribute('data-booking-state', 'confirmed');
      const confirmed = await page.request.get('/wp-json/agency/v1/bookings/' + booking.booking_id,
        { headers: { 'X-WP-Nonce': restNonce } });
      expect((await confirmed.json()).status).toBe('confirmed');
      await staff.screenshot({ path: `implementation/evidence/agency-confirm-${lang}.png`, fullPage: true, animations: 'disabled' });
      await page.reload({ waitUntil: 'domcontentloaded' });
      const voucher = page.locator('[data-atlas-confirmation-status="confirmed"] [data-atlas-voucher]').first();
      await expect(voucher).toBeVisible();
      const denied = await staff.request.get(await voucher.getAttribute('href'));
      expect(denied.status()).toBe(404);
      const printed = await page.request.get(await voucher.getAttribute('href'));
      expect(printed.status()).toBe(200);
      expect(printed.headers()['cache-control']).toContain('no-store');
      expect(await printed.text()).toContain(lang === 'fa' ? 'واچر خدمت' : 'Service voucher');
      expect(errors).toEqual([]);
    } finally {
      await staffContext.close();
    }
  });
}
