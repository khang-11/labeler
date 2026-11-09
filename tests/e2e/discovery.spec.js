// Finding a network printer and selecting it for this browser.
import { test, expect, ready, setSim } from './fixtures.js';

test('discover a network printer and use it', async ({ page }) => {
  setSim({
    printer: 'PT-P900W',
    tape_mm: 24,
    discovered: [{ host: '192.168.8.235', model: 'PT-P900W', serial: 'S1', media_width_mm: 24, ready: true }],
  });
  await ready(page);
  await expect(page.locator('#status-text')).toHaveText('PT-P900W · 24 mm');

  await page.locator('#status').click();
  await page.locator('#scan-printers').click();
  await expect(page.locator('#scan-results button')).toHaveText('PT-P900W · 192.168.8.235');
  await page.locator('#scan-results button').click();

  await expect(page.locator('#status-text')).toHaveText('PT-P900W · 24 mm');
  expect(await page.evaluate(() => localStorage.getItem('labeler.printer'))).toBe('net:192.168.8.235');
});

test('discovery reports that it is disabled', async ({ page }) => {
  setSim({ printer: 'PT-P700' });
  await ready(page);
  await page.route('**/api.php?action=discover', (route) => route.fulfill({
    status: 502,
    json: { error: 'Printer discovery is disabled. Set discovery_cidr in config.php.', printers: [] },
  }));
  await page.locator('#status').click();
  await page.locator('#scan-printers').click();
  await expect(page.locator('#scan-status')).toContainText('disabled');
});
