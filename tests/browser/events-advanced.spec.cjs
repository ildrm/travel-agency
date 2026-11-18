const { test, expect } = require('@playwright/test');

test('event QR image generation, local file decode and explicitly mocked camera release', async ({ page }) => {
  // This fixture never requests the machine's real camera; getUserMedia is replaced before a click.
  const reference = '1.aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa.bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb.1.' + 'a'.repeat(64);
  await page.goto('/');
  await page.setContent(`<html lang="en"><body><p><code data-event-reference="${reference}">${reference}</code></p><section data-event-offline data-user="qr-fixture" data-endpoint="/unused" data-nonce="unused" data-queued="Queued locally, not recorded" data-error="Retry"><textarea data-event-scans></textarea><button data-queue-event-scans>Queue</button><button data-sync-event-scans>Sync</button><p role="status"></p></section></body></html>`);
  await page.evaluate(() => { localStorage.removeItem('atlas-event-scans:qr-fixture'); window.qrCameraCalls=0; Object.defineProperty(navigator, 'mediaDevices', { configurable:true, value:{ getUserMedia:async()=>{window.qrCameraCalls++;return window.qrMockStream;} } }); });
  await page.addScriptTag({url:'/wp-content/plugins/atlas-operations/assets/events.js'});
  await page.addScriptTag({url:'/wp-content/plugins/atlas-operations/assets/events-qr.js'});
  expect(await page.evaluate(()=>window.qrCameraCalls)).toBe(0);
  await page.getByRole('button',{name:'Generate attendee QR image'}).click();
  await expect(page.locator('img[data-event-qr]')).toBeVisible();
  const svg = await page.evaluate(ref=>window.AtlasEventQR.encode(ref),reference);
  expect(svg).toContain('<svg');expect(svg).not.toContain(reference);
  await page.locator('[data-event-qr-file]').setInputFiles({name:'generated-attendee.svg',mimeType:'image/svg+xml',buffer:Buffer.from(svg)});
  await expect(page.locator('[data-event-offline] [role=status]')).toContainText('Queued locally');
  const queued = await page.evaluate(()=>JSON.parse(localStorage.getItem('atlas-event-scans:qr-fixture')));
  expect(queued.length).toBe(1);expect(queued[0].reference).toBe(reference);
  expect(await page.evaluate(()=>window.qrCameraCalls)).toBe(0);
  await page.getByRole('button',{name:'Prepare scanner before going offline'}).click();
  await expect(page.locator('[data-event-offline] [role=status]')).toContainText('Scanner loaded');
  expect(await page.evaluate(()=>window.qrCameraCalls)).toBe(0);
  await page.evaluate(async()=>{const image=document.querySelector('img[data-event-qr]');await image.decode();const canvas=document.createElement('canvas');canvas.width=512;canvas.height=512;canvas.getContext('2d').drawImage(image,0,0,512,512);window.qrMockCanvas=canvas;window.qrMockStream=canvas.captureStream(4);window.qrMockTracks=window.qrMockStream.getTracks();localStorage.removeItem('atlas-event-scans:qr-fixture');});
  await page.getByRole('button',{name:'Start camera scanning'}).click();
  await expect.poll(()=>page.evaluate(()=>window.qrCameraCalls)).toBe(1);
  await expect.poll(()=>page.evaluate(()=>JSON.parse(localStorage.getItem('atlas-event-scans:qr-fixture')||'[]').length)).toBe(1);
  await expect.poll(()=>page.evaluate(()=>window.qrMockTracks.every(track=>track.readyState==='ended'))).toBe(true);
  const malicious = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><script>window.bad=true</script></svg>';
  await page.locator('[data-event-qr-file]').setInputFiles({name:'invalid.svg',mimeType:'image/svg+xml',buffer:Buffer.from(malicious)});
  await expect(page.locator('[data-event-offline] [role=status]')).toContainText('No valid attendee QR');
  expect(await page.evaluate(()=>window.bad)).toBeUndefined();
  await page.evaluate(()=>localStorage.removeItem('atlas-event-scans:qr-fixture'));
});
