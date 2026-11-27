// Headless-browser smoke test of the playground. Serves demo/ itself, no other service needed.
//   cd demo && npm install && npx playwright install chromium && npm run smoke
// Locally you can point it at an installed browser: CHROME_PATH=/path/to/chrome PLAYWRIGHT_MODULE=/path/to/playwright/index.mjs
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';

const root = join(fileURLToPath(import.meta.url), '..', '..');
const TYPES = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.json': 'application/json' };

const server = createServer(async (req, res) => {
  const path = normalize(new URL(req.url, 'http://x').pathname).replace(/^(\.\.[/\\])+/, '');
  try {
    const file = join(root, path.endsWith('/') ? path + 'index.html' : path);
    res.writeHead(200, { 'Content-Type': TYPES[extname(file)] ?? 'application/octet-stream' });
    res.end(await readFile(file));
  } catch {
    res.writeHead(404).end('not found');
  }
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const url = `http://127.0.0.1:${server.address().port}/`;

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || undefined });
let failed = false;
const step = async (name, fn) => {
  try { await fn(); console.log('ok   ' + name); } catch (e) { failed = true; console.log('FAIL ' + name + '\n' + e.message); }
};

try {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
  const page = await context.newPage();
  const problems = [];
  page.on('pageerror', (e) => problems.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') problems.push(m.text()); });
  const external = [];
  page.on('request', (r) => { if (!r.url().startsWith(url)) external.push(r.url()); });

  await page.goto(url);
  await page.waitForSelector('#trace .step');

  await step('page basics: one h1, banner, lang, title', async () => {
    assert.equal(await page.locator('h1').count(), 1);
    assert.match(await page.locator('.banner').innerText(), /Static demo\. Runs in your browser on invented data; nothing is sent anywhere\./);
    assert.equal(await page.locator('html').getAttribute('lang'), 'en');
    assert.match(await page.title(), /daraja-php playground/);
  });

  await step('the seed request runs on load and the order is paid once', async () => {
    assert.match(await page.locator('#result .status').innerText(), /Paid/);
    assert.match(await page.locator('#result .facts').innerText(), /1 time/);
    const push = await page.locator('.step--http pre').nth(2).innerText();
    assert.match(push, /POST \/mpesa\/stkpush\/v1\/processrequest/);
    assert.match(push, /"PhoneNumber": 254700000001/);
  });

  await step('a duplicate callback is acknowledged and not processed twice', async () => {
    await page.click('[data-try=duplicate]');
    const facts = await page.locator('#result .facts').innerText();
    assert.match(facts, /1 time/);
    assert.match(facts, /2 \(handled, duplicate\)/);
    assert.match(await page.locator('#trace').innerText(), /stopped here/i);
  });

  await step('out of order: rejected with 422, then handled on retry', async () => {
    await page.click('[data-try=out_of_order]');
    const trace = await page.locator('#trace').innerText();
    assert.match(trace, /HTTP 422/);
    assert.match(trace, /Handled\. Your handler ran once\./);
    assert.match(await page.locator('#result .status').innerText(), /Paid/);
  });

  await step('a lost callback is settled by an STK query', async () => {
    await page.click('[data-try=lost]');
    assert.match(await page.locator('#result .facts').innerText(), /An STK query/);
  });

  await step('an invalid phone shows the library message next to the field and clears when fixed', async () => {
    await page.fill('#phone', '12345');
    await page.click('button.primary');
    assert.match(await page.locator('#phone-err').innerText(), /Phone number must be a Kenyan mobile number/);
    assert.equal(await page.locator('#phone').getAttribute('aria-invalid'), 'true');
    assert.match(await page.locator('#result .status').innerText(), /Not sent/);
    await page.fill('#phone', '+254 700-000-001');
    assert.equal(await page.locator('#phone-err').isHidden(), true);
    assert.equal(await page.locator('#phone-norm').innerText(), '254700000001');
  });

  await step('state survives a reload (local storage) and Reset restores the seed', async () => {
    await page.fill('#amount', '320');
    await page.reload();
    await page.waitForSelector('#trace .step');
    assert.equal(await page.inputValue('#amount'), '320');
    await page.click('#reset');
    assert.equal(await page.inputValue('#amount'), '150');
    assert.equal(await page.inputValue('#phone'), '0700 000 001');
  });

  await step('keyboard: the form submits with Enter and every control has a name', async () => {
    await page.focus('#accountReference');
    await page.keyboard.press('Enter');
    assert.match(await page.locator('#announce').innerText(), /Paid/);
    const unnamed = await page.evaluate(() => [...document.querySelectorAll('input,button,select,textarea')]
      .filter((el) => !(el.labels?.length || el.getAttribute('aria-label') || el.textContent.trim()))
      .map((el) => el.outerHTML.slice(0, 80)));
    assert.deepEqual(unnamed, []);
  });

  await step('no horizontal scroll at 320, 390, 768 and 1440', async () => {
    for (const w of [320, 390, 768, 1440]) {
      await page.setViewportSize({ width: w, height: 800 });
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      assert.ok(overflow <= 0, `${w}px overflows by ${overflow}px`);
    }
  });

  await step('no console errors and no request left the page origin', async () => {
    assert.deepEqual(problems, []);
    assert.deepEqual(external, []);
  });
} finally {
  await browser.close();
  server.close();
}
process.exit(failed ? 1 : 0);
