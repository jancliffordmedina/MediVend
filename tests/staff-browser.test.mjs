import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { createServer } from 'node:net';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { randomBytes } from 'node:crypto';

// Reuse an installed Puppeteer module and Chrome. Never downloads dependencies.
const imported = await import(process.env.MEDIVEND_PUPPETEER_MODULE || 'puppeteer');
const puppeteer = imported.puppeteer || imported.default;
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const php = process.env.MEDIVEND_PHP_PATH || (process.platform === 'win32' ? 'C:/xampp/php/php.exe' : 'php');
const name = 'medivend_staffbrowser_' + randomBytes(6).toString('hex');
const output = mkdtempSync(join(tmpdir(), 'medivend-staff-browser-'));
const password = randomBytes(18).toString('hex');
const username = 'browser-tester';
const checks = [], errors = [], submitted = [];
const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
const pass = message => { checks.push(message); console.log('PASS:', message); };
let created = false, server, browser;

function database(operation) {
  const code = `require 'includes/database.php';
    $input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
    $name=$input['name'];
    if (!preg_match('/\\Amedivend_staffbrowser_[a-f0-9]{12}\\z/',$name)) throw new RuntimeException('Unsafe test database');
    $db=medivendDatabase();
    if ($input['operation']==='create') $db->exec('CREATE DATABASE \x60'.$name.'\x60 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    elseif ($input['operation']==='drop') $db->exec('DROP DATABASE \x60'.$name.'\x60');
    else {
      $db->exec('USE \x60'.$name.'\x60');
      if ($input['operation']==='seed') {
        $insert=$db->prepare('INSERT INTO products(sku,name,price_centavos,stock_quantity,compartment_number,is_active,is_demo) VALUES(?,?,1000,?,?,1,1)');
        foreach([['BROWSER-CETI','Cetirizine',5,1],['BROWSER-LOPE','Loperamide',0,2],['BROWSER-PARA','Paracetamol',3,3]] as $row) $insert->execute($row);
      }
      echo json_encode(['products'=>$db->query('SELECT id,sku,stock_quantity FROM products ORDER BY id')->fetchAll(),
        'history'=>$db->query('SELECT event_id,product_id,quantity,stock_before,stock_after FROM staff_stock_events ORDER BY event_id')->fetchAll()],JSON_THROW_ON_ERROR);
    }`;
  const text = execFileSync(php, ['-r', code], { cwd: root, input: JSON.stringify({ name, operation }), encoding: 'utf8', windowsHide: true });
  return text ? JSON.parse(text) : null;
}

async function availablePort() {
  const listener = createServer();
  await new Promise((resolve, reject) => { listener.once('error', reject); listener.listen(0, '127.0.0.1', resolve); });
  const port = listener.address().port;
  await new Promise(resolve => listener.close(resolve));
  return port;
}

try {
  database('create'); created = true;
  const hash = execFileSync(php, ['-r', 'echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);'], { input: password, encoding: 'utf8', windowsHide: true });
  const env = { ...process.env, MEDIVEND_DB_NAME: name, MEDIVEND_MODE: 'simulation', MEDIVEND_STAFF_USERNAME: username,
    MEDIVEND_STAFF_PASSWORD_HASH: hash, MEDIVEND_STAFF_RATE_DIR: join(output, 'rate') };
  execFileSync(php, [join(root, 'scripts/migrate.php')], { cwd: root, env, windowsHide: true });
  const seeded = database('seed'); const [a, b] = seeded.products.map(row => Number(row.id));
  mkdirSync(join(output, 'sessions'));
  const port = await availablePort(), base = `http://127.0.0.1:${port}/`;
  let serverLog = '';
  server = spawn(php, ['-d', `session.save_path=${join(output, 'sessions')}`, '-S', `127.0.0.1:${port}`, '-t', root],
    { cwd: root, env, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
  server.stdout.on('data', data => { serverLog += data; }); server.stderr.on('data', data => { serverLog += data; });
  for (let i = 0; i < 60; i++) {
    try { if ((await fetch(base + 'api/staff-session.php')).ok) break; } catch (_) { /* wait for listener */ }
    if (i === 59) throw new Error('Test server failed to start: ' + serverLog); await wait(50);
  }
  console.log('Browser artifacts:', output);
  browser = await puppeteer.launch({ headless: true, executablePath: process.env.MEDIVEND_CHROME_PATH
    || (process.platform === 'win32' ? 'C:/Program Files/Google/Chrome/Application/chrome.exe' : undefined),
    userDataDir: join(output, 'chrome'), args: ['--no-first-run', '--no-default-browser-check'] });
  const page = await browser.newPage();
  page.on('pageerror', error => errors.push(error.message));
  await page.setViewport({ width: 1024, height: 600, hasTouch: true });
  let dropNext = false, failRemovalNext = false, rejectNext = false, rejectedEvent = null;
  await page.setRequestInterception(true);
  page.on('request', request => {
    (async () => {
      if (new URL(request.url()).pathname.endsWith('/api/staff-stock.php') && request.method() === 'POST') {
        const intent = JSON.parse(request.postData());
        submitted.push(intent);
        if (failRemovalNext) {
          failRemovalNext = false;
          // The pending payload has already been saved when fetch starts.
          await page.evaluate(() => {
            window.__staffOriginalRemoveItem = Storage.prototype.removeItem;
            Storage.prototype.removeItem = function (key) {
              if (key === 'medivend.staff-restock.v1') throw new DOMException('Test storage removal failure', 'SecurityError');
              return window.__staffOriginalRemoveItem.call(this, key);
            };
          });
        }
        if (rejectNext) { rejectNext = false; rejectedEvent = intent.event_id; }
        if (rejectedEvent === intent.event_id) {
          await request.respond({ status: 422, contentType: 'application/json', body: JSON.stringify({ error: {
            code: 'invalid_restock', message: 'Validation fixture rejected this addition.'
          } }) });
          return;
        }
        if (dropNext) {
          dropNext = false;
          const cookies = await page.browserContext().cookies(base);
          const response = await fetch(request.url(), { method: 'POST', body: request.postData(), headers: {
            'Content-Type': 'application/json', 'X-CSRF-Token': request.headers()['x-csrf-token'],
            Cookie: cookies.map(cookie => cookie.name + '=' + cookie.value).join('; ')
          } });
          assert.equal(response.status, 200, await response.text());
          await request.abort('failed'); return;
        }
      }
      await request.continue();
    })().catch(error => { errors.push(error.message); request.abort().catch(() => {}); });
  });
  const idle = () => page.waitForFunction(() => document.querySelector('#stock-panel').getAttribute('aria-busy') === 'false');
  const count = id => page.$eval(`[data-restock="${id}"]`, button => Number(button.closest('.stock-card').querySelector('.stock-count strong').textContent.replaceAll(',', '')));
  async function screen(label) {
    await page.evaluate(() => document.fonts.ready);
    const layout = await page.evaluate(() => ({ width: innerWidth, scrollWidth: document.documentElement.scrollWidth,
      small: [...document.querySelectorAll('button')].filter(button => {
        const rect = button.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0 && getComputedStyle(button).visibility !== 'hidden' && (rect.width < 43.9 || rect.height < 43.9);
      }).map(button => button.textContent.trim()) }));
    assert.ok(layout.scrollWidth <= layout.width, label + ': horizontal overflow');
    assert.deepEqual(layout.small, [], label + ': buttons below 44 pixels');
    await page.screenshot({ path: join(output, label + '.png'), fullPage: true });
  }
  async function login(secret) {
    await page.$eval('#username', input => { input.value = ''; });
    await page.type('#username', username); await page.type('#password', secret); await page.click('#login-submit');
  }
  async function add(id, quantity, note, repeated = false) {
    await page.click(`[data-restock="${id}"]`);
    await page.waitForFunction(() => document.querySelector('#restock-dialog').open);
    await page.type('#quantity', String(quantity)); await page.type('#restock-note', note);
    await screen('restock-dialog-desktop');
    if (repeated) await page.evaluate(() => { for (let i = 0; i < 5; i++) document.querySelector('#record-restock').click(); });
    else await page.click('#record-restock');
  }
  await page.goto(base + 'staff.php', { waitUntil: 'load' });
  await page.waitForSelector('#login-submit:not(:disabled)');
  await screen('login-desktop');
  assert.equal((await fetch(base + 'api/staff-stock.php')).status, 401);
  await login('wrong-password'); await page.waitForSelector('#login-error:not([hidden])');
  assert.equal(await page.$eval('#stock-panel', element => element.hidden), true);
  assert.equal(await page.$eval('#password', input => input.value), '');
  pass('Anonymous inventory is protected; wrong login shows an error and clears the password');
  await login(password); await page.waitForSelector('[data-restock]'); await idle();
  assert.equal(await count(a), 5); assert.equal(await count(b), 0);
  assert.equal(await page.$eval('#summary-empty', element => element.textContent), '1');
  assert.match(await page.$eval('#mode-note', element => element.textContent), /Test inventory/);
  await screen('stock-desktop');
  await add(a, 10, 'Counted ten test units', true);
  await page.waitForFunction(() => document.querySelector('#page-message').textContent.includes('10 units added'));
  await idle(); assert.equal(await count(a), 15); assert.equal(submitted.length, 1);
  assert.match(await page.$eval('#history', element => element.textContent), /Counted ten test units/);
  let snapshot = database('snapshot'); assert.equal(snapshot.history.length, 1); assert.equal(snapshot.products[0].stock_quantity, 15);
  await page.reload({ waitUntil: 'load' }); await page.waitForSelector('[data-restock]'); await idle(); assert.equal(await count(a), 15);
  pass('Restocking adds to the existing count, records history, survives refresh, and rapid submits add once');
  dropNext = true; await add(b, 4, 'Four test units, lost response');
  await page.waitForSelector('#pending-panel:not([hidden])');
  await page.waitForFunction(() => document.querySelector('#page-message').textContent.includes('not confirmed'));
  await idle(); const original = submitted.at(-1);
  snapshot = database('snapshot'); assert.equal(snapshot.products[1].stock_quantity, 4); assert.equal(snapshot.history.length, 2);
  assert.equal(await page.$eval(`[data-restock="${a}"]`, button => button.disabled), true);
  await page.reload({ waitUntil: 'load' }); await page.waitForSelector('#pending-panel:not([hidden])'); await idle();
  await screen('saved-restock-desktop');
  await page.click('#retry-restock');
  await page.waitForFunction(() => document.querySelector('#page-message').textContent.includes('saved record confirmed'));
  await idle(); assert.deepEqual(submitted.at(-1), original); assert.equal(await count(b), 4);
  assert.equal(await page.$eval('#pending-panel', element => element.hidden), true);
  snapshot = database('snapshot'); assert.equal(snapshot.products[1].stock_quantity, 4); assert.equal(snapshot.history.length, 2);
  pass('A lost response preserves the exact request across reload and replay cannot double the restock');
  await screen('history-desktop');
  failRemovalNext = true; await add(a, 2, 'Committed addition with blocked browser cleanup');
  await page.waitForFunction(() => document.querySelector('#page-message').textContent.includes('Browser storage could not be cleared'));
  await idle();
  const cleanupIntent = submitted.at(-1);
  const committedMessage = await page.$eval('#page-message', element => element.textContent);
  assert.match(committedMessage, /2 units added/); assert.doesNotMatch(committedMessage, /not confirmed|Connection interrupted/);
  assert.equal(await count(a), 17);
  assert.equal(await page.$eval('#pending-panel', element => element.hidden), false);
  assert.equal(await page.$eval(`[data-restock="${b}"]`, button => button.disabled), true);
  snapshot = database('snapshot'); assert.equal(snapshot.products[0].stock_quantity, 17); assert.equal(snapshot.history.length, 3);
  assert.deepEqual(await page.evaluate(() => JSON.parse(sessionStorage.getItem('medivend.staff-restock.v1')).body), cleanupIntent);
  await screen('committed-restock-storage-error');
  await page.evaluate(() => { Storage.prototype.removeItem = window.__staffOriginalRemoveItem; });
  await page.click('#retry-restock');
  await page.waitForFunction(() => document.querySelector('#pending-panel').hidden);
  await idle(); assert.deepEqual(submitted.at(-1), cleanupIntent);
  snapshot = database('snapshot'); assert.equal(snapshot.products[0].stock_quantity, 17); assert.equal(snapshot.history.length, 3);
  assert.equal(await page.evaluate(() => sessionStorage.getItem('medivend.staff-restock.v1')), null);
  pass('Storage removal failure after commit shows authoritative success and blocks additions until exact replay clears it');
  failRemovalNext = true; rejectNext = true;
  await add(a, 1, 'Rejected addition with blocked browser cleanup');
  await page.waitForFunction(() => document.querySelector('#page-message').textContent.includes('Restore browser storage access'));
  await idle(); const rejectedIntent = submitted.at(-1);
  const rejectionMessage = await page.$eval('#page-message', element => element.textContent);
  assert.match(rejectionMessage, /^Validation fixture rejected this addition\./);
  assert.doesNotMatch(rejectionMessage, /not confirmed|Connection interrupted/);
  assert.equal(await page.$eval('#pending-panel', element => element.hidden), false);
  assert.equal(await page.$eval(`[data-restock="${a}"]`, button => button.disabled), true);
  assert.equal(await count(a), 17);
  await page.evaluate(() => { Storage.prototype.removeItem = window.__staffOriginalRemoveItem; });
  await page.click('#retry-restock');
  await page.waitForFunction(() => document.querySelector('#pending-panel').hidden);
  await idle(); assert.deepEqual(submitted.at(-1), rejectedIntent);
  assert.equal(await page.evaluate(() => sessionStorage.getItem('medivend.staff-restock.v1')), null);
  assert.equal(await page.$eval(`[data-restock="${a}"]`, button => button.disabled), false);
  snapshot = database('snapshot'); assert.equal(snapshot.products[0].stock_quantity, 17); assert.equal(snapshot.history.length, 3);
  pass('Storage removal failure on definitive422 preserves validation feedback and saved request, then clears safely on retry');
  await page.setViewport({ width: 390, height: 844, hasTouch: true }); await screen('stock-mobile');
  await page.click(`[data-restock="${a}"]`); await screen('restock-dialog-mobile'); await page.click('#close-restock');
  await page.click('#staff-logout'); await page.waitForSelector('#login-panel:not([hidden])'); await idle();
  assert.equal(await page.$eval('#products', element => element.childElementCount), 0);
  assert.equal(await page.$eval('#history', element => element.childElementCount), 0);
  await screen('login-mobile');
  await login(password); await page.waitForSelector('[data-restock]'); await idle(); assert.equal(await count(a), 17);
  pass('Sign-out removes inventory from the page; login and restock controls fit desktop and mobile touch layouts');
  assert.deepEqual(errors, [], 'No uncaught browser or interception errors');
  writeFileSync(join(output, 'results.json'), JSON.stringify({ checks, errors, database: name }, null, 2));
  writeFileSync(join(output, 'server.log'), serverLog);
  console.log('PASS: all staff browser checks.');
} finally {
  if (browser) await browser.close();
  if (server) { server.kill(); await Promise.race([new Promise(resolve => server.once('exit', resolve)), wait(3000)]); }
  if (created) database('drop');
  console.log('Isolated staff browser database removed. Existing inventory was not changed.');
}
