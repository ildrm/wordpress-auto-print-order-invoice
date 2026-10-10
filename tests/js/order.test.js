'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../assets/js/order.js'), 'utf8');
function environment({ output = 'browser', copies = '2' } = {}) {
  const listeners = {};
  const opened = [];
  const requests = [];
  const status = { textContent: '' };
  const dialog = {
    dataset: { orderId: '42', previewBase: 'https://example.test/wp-admin/admin-post.php?action=wcip_preview&_wpnonce=nonce&order_ids=42' },
    querySelector(selector) { return { '#wcip-order-template': { value: 'thermal' }, '#wcip-order-output': { value: output }, '#wcip-order-copies': { value: copies }, '[data-wcip-print-status]': status }[selector]; }
  };
  const config = { rest: 'https://example.test/wp-json/wc-invoice-printer/v1/print', nonce: 'nonce', printer: '123', agentQueue: 'Office', sending: 'Queuing', queued: 'Invoice queued', failed: 'Failed', invalidCopies: 'Invalid copies' };
  vm.runInNewContext(source, { document: { addEventListener(name, callback) { listeners[name] = callback; } }, window: { wcipOrderPrint: config, open(url) { opened.push(url); } }, URL, fetch: async (url, options) => { requests.push({ url, options }); return { ok: true, async json() { return { queued: 1 }; } }; } });
  async function click(action) {
    const button = { disabled: false, setAttribute() {}, removeAttribute() {} };
    await listeners.click({ target: { closest(selector) { return selector === '.wcip-order-dialog' ? dialog : selector === '[data-wcip-' + action + ']' ? button : null; } } });
    return button;
  }
  return { opened, requests, status, click };
}

test('preview is read-only and shows one copy', async () => {
  const env = environment({ output: 'cups', copies: '3' });
  await env.click('preview');
  const url = new URL(env.opened[0]);
  assert.equal(url.searchParams.get('preview'), '1');
  assert.equal(url.searchParams.get('copies'), '1');
  assert.equal(url.searchParams.get('print'), null);
  assert.equal(env.requests.length, 0);
});

test('browser Print opens the print dialog for the requested copies', async () => {
  const env = environment();
  await env.click('submit');
  const url = new URL(env.opened[0]);
  assert.equal(url.searchParams.get('print'), '1');
  assert.equal(url.searchParams.get('copies'), '2');
});

test('CUPS submission reports queued instead of provider acceptance', async () => {
  const env = environment({ output: 'cups' });
  const button = await env.click('submit');
  const body = JSON.parse(env.requests[0].options.body);
  assert.equal(new URL(env.requests[0].url).searchParams.get('_locale'), 'user');
  assert.deepEqual(body, { order_ids: [42], template_id: 'thermal', provider_id: 'cups', printer_id: '123', copies: 2 });
  assert.equal(env.status.textContent, 'Invoice queued');
  assert.equal(button.disabled, false);
});

test('invalid copies do not open a window or send a request', async () => {
  const env = environment({ copies: '21' });
  await env.click('submit');
  assert.equal(env.opened.length, 0);
  assert.equal(env.requests.length, 0);
  assert.equal(env.status.textContent, 'Invalid copies');
});

test('agent submission sends the paired route and waits for delivery', async () => {
  const env = environment({ output: 'agent' });
  await env.click('submit');
  const body = JSON.parse(env.requests[0].options.body);
  assert.equal(body.provider_id, 'agent');
  assert.equal(body.printer_id, 'Office');
  assert.equal(env.opened.length, 0);
  assert.equal(env.status.textContent, 'Invoice queued');
});
