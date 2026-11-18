const { chromium } = require('@playwright/test');
const path = require('node:path');
const crypto = require('node:crypto');
const root = path.resolve(__dirname, '..');
(async () => {
  const browser = await chromium.launch({
    headless: true,
    executablePath: process.env.ATLAS_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    args: ['--disable-gpu', '--disable-background-networking', '--renderer-process-limit=2']
  });
  try {
    const page = await browser.newPage({ viewport: { width: 1200, height: 900 }, reducedMotion: 'reduce' });
    const response = await page.goto('http://127.0.0.1:8840/?lang=en', { waitUntil: 'networkidle' });
    const expected = crypto.createHash('sha256').update(root).digest('hex').slice(0, 16);
    if (response.headers()['x-atlas-local-runtime'] !== expected) throw new Error('Unowned local runtime; capture refused');
    await page.locator('#atlas-home-title').waitFor();
    await page.screenshot({ path: path.join(root, 'wp-content/themes/atlas-leisure/screenshot.png'), animations: 'disabled' });
    process.stdout.write('Captured actual 1200x900 theme preview in headless Chrome.\n');
  } finally {
    await browser.close();
  }
})().catch(error => { process.stderr.write(error.message + '\n'); process.exitCode = 1; });
