const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: './tests/browser',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 45000,
  reporter: [['list'], ['json', { outputFile: 'implementation/evidence/browser-results.json' }]],
  use: {
    baseURL: 'http://127.0.0.1:8840',
    headless: true,
    viewport: { width: 1440, height: 1000 },
    reducedMotion: 'reduce',
    launchOptions: {
      executablePath: process.env.ATLAS_CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
      args: ['--disable-gpu', '--disable-background-networking', '--renderer-process-limit=2']
    }
  }
});
