const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { randomUUID } = require('node:crypto');
const root = path.resolve(__dirname, '../..');

async function signIn(page, origin, credentials, actor, destination) {
  await page.goto(origin + '/wp-login.php?redirect_to=' + encodeURIComponent(new URL(destination, origin).href));
  await expect(page.locator('#user_login')).toBeFocused();
  await page.locator('#user_login').fill(credentials[actor + '_user']);
  await page.locator('#user_pass').fill(credentials[actor + '_password']);
  expect(await page.locator('#user_pass').inputValue() === credentials[actor + '_password']).toBe(true);
  await page.locator('#wp-submit').click();
  await page.waitForURL(url => url.pathname !== '/wp-login.php');
}

async function chooseAddressOption(page, selector, value, text) {
  const field = page.locator(selector); await expect(field).toBeVisible();
  if (await field.evaluate(element => element.tagName) === 'SELECT') await field.selectOption(value);
  else { await field.fill(text); await page.getByRole('option', { name: text, exact: true }).click(); }
}

function wholeIrr(totals) {
  expect(totals.currency_code).toBe('IRR');
  const precision = Number(totals.currency_minor_unit); expect(Number.isInteger(precision) && precision >= 0 && precision <= 6).toBe(true);
  const divisor = 10n ** BigInt(precision); const raw = BigInt(totals.total_price);
  expect(raw % divisor).toBe(0n); return Number(raw / divisor);
}

test('dated stay -> exact Checkout Blocks simulation -> supplier acceptance -> agency confirmation', async ({ page, browser }) => {
  test.setTimeout(90000);
  const fixturesPath = path.join(root, '.runtime/resource-browser-fixtures.json');
  const credentialsPath = path.join(root, '.runtime/resource-browser-credentials.json');
  expect(fs.existsSync(fixturesPath) && fs.existsSync(credentialsPath), 'Run the local WP-CLI seed-resource-browser.php fixture before this spec.').toBe(true);
  const fixture = JSON.parse(fs.readFileSync(fixturesPath, 'utf8'));
  const credentials = JSON.parse(fs.readFileSync(credentialsPath, 'utf8'));
  const origin = new URL(fixture.base_url).origin;
  expect(['127.0.0.1', 'localhost', '[::1]', '::1']).toContain(new URL(origin).hostname);
  expect(fixture.expected_amount_irr).toBe(fixture.nights * fixture.nightly_irr + fixture.tax_per_unit_irr);
  expect(fixture.placeholder_product_price_irr).not.toBe(fixture.expected_amount_irr);
  const errors = []; const recordErrors = error => errors.push(error.message); page.on('pageerror', recordErrors);
  const evidence = path.join(root, 'implementation/evidence'); fs.mkdirSync(evidence, { recursive: true });
  const stages = [];
  const supplierContext = await browser.newContext({ baseURL: origin, viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  const agencyContext = await browser.newContext({ baseURL: origin, viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  try {
    await signIn(page, origin, credentials, 'customer', '/?atlas_area=account&lang=en');
    await page.goto(origin + '/?offer_id=' + fixture.offer_id + '&lang=en');
    const form = page.locator('form[data-atlas-booking]'); await expect(form).toBeVisible();
    const fields = form.locator('[data-resource-booking]'); await expect(fields).toBeVisible();
    const restNonce = await page.evaluate(() => window.atlasBooking.restNonce);
    const storeNonce = await page.evaluate(() => window.atlasBooking.storeNonce);
    const ownReservations = await page.request.get(origin + '/wp-json/agency/v1/resource-bookings', { headers: { 'X-WP-Nonce': restNonce } });
    expect(ownReservations.ok()).toBe(true);
    expect((await ownReservations.json()).items, 'Use a newly seeded resource customer; do not overwrite or reuse an already paid fixture.').toEqual([]);
    expect((await page.request.delete(origin + '/wp-json/wc/store/v1/cart/items', { headers: { Nonce: storeNonce, 'X-WP-Nonce': restNonce } })).status()).toBe(200);
    await expect(fields.locator('[name="check_in"]')).toBeVisible();
    await expect(fields.locator('[name="check_out"]')).toBeVisible();
    await expect(fields.locator('[name="guests_per_unit"]')).toBeVisible();
    await fields.locator('[name="check_in"]').fill(fixture.check_in);
    await fields.locator('[name="check_out"]').fill(fixture.check_out);
    await fields.locator('[name="guests_per_unit"]').fill(String(fixture.guests_per_unit));
    await form.locator('[name="quantity"]').fill(String(fixture.quantity));
    await expect(fields.locator('[data-resource-estimate]')).toContainText(new Intl.NumberFormat('en').format(fixture.expected_amount_irr) + ' IRR');
    await expect(fields).toContainText(fixture.timezone);
    await fields.locator('[name="terms_accepted"]').check();
    await fields.screenshot({ path: path.join(evidence, 'resource-stay-dates-en.png'), animations: 'disabled' });
    const holdResponse = page.waitForResponse(response => response.url().includes('/agency/v1/holds') && response.request().method() === 'POST');
    await form.locator('[data-atlas-reserve]').click();
    const held = await holdResponse; expect(held.status()).toBe(201); const booking = await held.json();
    expect(booking.quote).toMatchObject({ amount: fixture.expected_amount_irr, currency: 'IRR', unit: 'rial' });
    stages.push({ stage: 'dated_hold', amount_irr: booking.quote.amount });
    await page.waitForURL(url => url.pathname.includes('/checkout'));
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.locator('.wp-block-woocommerce-checkout')).toBeVisible();
    const cart = await page.request.get(origin + '/wp-json/wc/store/v1/cart', { headers: { Nonce: storeNonce, 'X-WP-Nonce': restNonce } });
    expect(cart.ok()).toBe(true); const cartData = await cart.json();
    expect(cartData.items).toHaveLength(1); expect(cartData.items[0].id).toBe(fixture.product_id); expect(cartData.items[0].quantity).toBe(fixture.quantity);
    expect(wholeIrr(cartData.totals)).toBe(fixture.expected_amount_irr);
    const total = page.locator('.wc-block-components-totals-footer-item:visible').first(); await expect(total).toBeVisible(); await expect(total).toContainText(/24[,\s]?100[,\s]?000/);
    const email = page.locator('#email'); if (await email.count()) await email.fill(fixture.customer_email);
    const editAddress = page.getByRole('button', { name: /Edit billing address/ }); if (await editAddress.isVisible()) await editAddress.click();
    await chooseAddressOption(page, '#billing-country', 'IR', 'Iran');
    await page.locator('#billing-first_name').fill('Fictional'); await page.locator('#billing-last_name').fill('Stay customer');
    await page.locator('#billing-address_1').fill('1 Fictional Stay Road'); await page.locator('#billing-city').fill('Tehran');
    await chooseAddressOption(page, '#billing-state', 'THR', 'تهران'); await page.locator('#billing-postcode').fill('1111111111');
    const phone = page.locator('#billing-phone'); if (await phone.isVisible()) await phone.fill('+12025550123');
    const simulator = page.locator('input[value="atlas_simulator"]'); await expect(simulator).toBeAttached();
    if (!(await simulator.isChecked())) await page.locator('label[for="' + await simulator.getAttribute('id') + '"]').click();
    for (const control of await page.locator('.wc-block-checkout__terms input[type="checkbox"]').all()) await control.check();
    await page.screenshot({ path: path.join(evidence, 'resource-stay-checkout-en.png'), fullPage: true, animations: 'disabled' });
    const checkoutResponse = page.waitForResponse(response => response.url().includes('/wc/store/v1/checkout') && response.request().method() === 'POST');
    await page.locator('.wc-block-components-checkout-place-order-button').click();
    const checkout = await checkoutResponse; const order = await checkout.json();
    expect(checkout.ok(), order.message || 'Fictional stay Checkout Blocks payment must succeed.').toBe(true);
    await page.waitForURL(url => url.pathname.includes('order-received'));
    await expect(page.locator('[data-atlas-confirmation-status="paid_pending_confirmation"]').first()).toBeVisible();
    const customerState = async () => { const response = await page.request.get(origin + '/wp-json/agency/v1/bookings/' + booking.booking_id, { headers: { 'X-WP-Nonce': restNonce } }); expect(response.status()).toBe(200); return response.json(); };
    const paid = await customerState(); expect(paid.amount_irr).toBe(fixture.expected_amount_irr); expect(paid.payment_status).toBe('verified'); expect(paid.status).toBe('paid_pending_confirmation');
    stages.push({ stage: 'local_simulation_verified', status: paid.status, amount_irr: paid.amount_irr });
    const agency = await agencyContext.newPage(); agency.on('pageerror', recordErrors);
    await signIn(agency, origin, credentials, 'agency', '/?atlas_area=portal&lang=en');
    const agencyNonce = await agency.evaluate(() => window.atlasResources.nonce);
    const premature = await agency.request.post('/wp-json/agency/v1/bookings/' + booking.booking_id + '/confirm', { headers: { 'X-WP-Nonce': agencyNonce, 'Idempotency-Key': randomUUID() }, data: { reason: 'Fictional attempt before supplier acceptance; expected to be blocked.' } });
    expect(premature.status()).toBe(409); expect((await premature.json()).code).toBe('atlas_resource_supplier_confirmation');
    const supplier = await supplierContext.newPage(); supplier.on('pageerror', recordErrors);
    await signIn(supplier, origin, credentials, 'supplier', '/?atlas_area=portal&lang=en');
    const supplierWorkspace = supplier.locator('[data-resource-workspace]'); await expect(supplierWorkspace).toBeVisible();
    await supplierWorkspace.locator('[data-resource-refresh]').click();
    const supplierCard = supplierWorkspace.locator('[data-resource-queue] article').filter({ hasText: fixture.check_in });
    await expect(supplierCard).toHaveCount(1); await expect(supplierCard).toBeVisible();
    const supplierForm = supplierCard.locator('form').filter({ has: supplier.getByRole('button', { name: 'Record supplier decision', exact: true }) });
    await supplierForm.locator('select').selectOption('true');
    await supplierForm.getByLabel('Private evidence reference', { exact: true }).fill('fictional-browser-supplier-acceptance');
    await supplierForm.getByLabel('Reason', { exact: true }).fill('Fictional operator checked the three requested local nights and agreed terms.');
    const supplierResponse = supplier.waitForResponse(response => response.url().includes('/resource-bookings/' + booking.booking_id + '/supplier-review') && response.request().method() === 'POST');
    await supplierForm.getByRole('button', { name: 'Record supplier decision', exact: true }).click();
    const accepted = await supplierResponse; expect(accepted.ok()).toBe(true); expect((await accepted.json()).fields.supplier_status).toBe('accepted');
    await expect(supplierCard).toContainText('Supplier accepted; agency confirmation is separate');
    await supplierCard.screenshot({ path: path.join(evidence, 'resource-stay-supplier-en.png'), animations: 'disabled' });
    expect((await customerState()).status).toBe('paid_pending_confirmation'); stages.push({ stage: 'supplier_accepted', status: 'paid_pending_confirmation' });
    const queue = agency.locator('[data-atlas-booking-queue]'); const details = queue.locator('xpath=..');
    if ((await details.getAttribute('open')) === null) await details.locator('summary').click();
    let row = queue.locator('tbody tr').filter({ hasText: booking.booking_id });
    for (let index = 0; index < 20; index++) {
      await expect(queue.locator('[role="status"]')).not.toContainText('Loading reservations');
      if (await row.count()) break;
      const next = queue.locator('[data-next-page]'); expect(await next.isEnabled(), 'Agency queue must be able to locate the new fictional stay.').toBe(true); await next.click();
    }
    await expect(row).toBeVisible();
    await row.locator('input[name="reason"]').fill('Fictional agency reviewed the supplier acceptance and exact paid stay quote.');
    const confirmationResponse = agency.waitForResponse(response => response.url().includes('/bookings/' + booking.booking_id + '/confirm') && response.request().method() === 'POST');
    await row.locator('button[type="submit"]').click(); const confirmation = await confirmationResponse; expect(confirmation.ok()).toBe(true);
    await expect(row).toContainText('confirmed'); await row.screenshot({ path: path.join(evidence, 'resource-stay-agency-en.png'), animations: 'disabled' });
    const confirmed = await customerState(); expect(confirmed.status).toBe('confirmed'); expect(confirmed.payment_status).toBe('verified'); expect(confirmed.amount_irr).toBe(fixture.expected_amount_irr);
    await page.reload(); await expect(page.locator('[data-atlas-confirmation-status="confirmed"]').first()).toBeVisible();
    await page.screenshot({ path: path.join(evidence, 'resource-stay-receipt-en.png'), fullPage: true, animations: 'disabled' });
    stages.push({ stage: 'agency_confirmed', status: confirmed.status });
    // Deliberately exclude credentials, cookies, nonces, order keys, billing fields and provider references.
    const environment = await page.evaluate(() => ({ user_agent: navigator.userAgent, width: innerWidth, height: innerHeight }));
    fs.writeFileSync(path.join(evidence, 'resource-browser-journey.json'), JSON.stringify({ fixture_run: fixture.fixture_run, scope: 'one fictional local headless desktop stay journey; no live supplier/payment or broader browser claim', environment, language: 'en', check_in: fixture.check_in, check_out: fixture.check_out, timezone: fixture.timezone, nights: fixture.nights, quantity: fixture.quantity, guests_per_unit: fixture.guests_per_unit, expected_amount_irr: fixture.expected_amount_irr, observed_cart_amount_irr: wholeIrr(cartData.totals), booking_id: booking.booking_id, order_id: order.order_id, stages, browser_errors: errors }, null, 2));
    expect(errors).toEqual([]);
  } finally { await supplierContext.close(); await agencyContext.close(); }
});
