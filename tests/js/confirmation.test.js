'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../assets/js/confirmation.js'), 'utf8');

function environment({ ok = true, data = { printed: true }, endpoint = 'https://example.test/?rest_route=/wc-invoice-printer/v1/printed' } = {}) {
  const listeners = {};
  const requests = [];
  const status = { textContent: '' };
  const label = { textContent: 'Not printed' };
  const button = {
    disabled: false, textContent: 'Confirm printed',
    dataset: { jobIds: '7,8', endpoint, nonce: 'nonce', working: 'Working', printed: 'Printed', success: 'Note added', failed: 'Failed' },
    setAttribute() {}, removeAttribute() {},
    closest(selector) { return selector === '.wcip-confirmation' ? { querySelector() { return status; } } : { querySelector() { return label; } }; }
  };
  vm.runInNewContext(source, {
    document: { addEventListener(name, fn) { listeners[name] = fn; } }, URL,
    fetch: async (url, options) => { requests.push({ url, options }); return { ok, json: async () => data }; }
  });
  return { button, status, label, requests, click: async (confirm = true) => listeners.click({ target: { closest() { return confirm ? button : null; } } }) };
}

test('printing or cancelling never confirms output without the explicit confirmation click', async () => {
  const env = environment();
  assert.equal(env.requests.length, 0);
  await env.click(false);
  assert.equal(env.requests.length, 0);
  assert.equal(env.label.textContent, 'Not printed');
});

test('explicit bulk confirmation sends job IDs and a REST nonce, preserving plain permalinks', async () => {
  const env = environment();
  await env.click();
  const request = env.requests[0];
  assert.equal(new URL(request.url).searchParams.get('rest_route'), '/wc-invoice-printer/v1/printed');
  assert.equal(new URL(request.url).searchParams.get('_locale'), 'user');
  assert.equal(request.options.headers['X-WP-Nonce'], 'nonce');
  assert.deepEqual(JSON.parse(request.options.body), { job_ids: [7, 8] });
  assert.equal(env.label.textContent, 'Printed');
  assert.equal(env.status.textContent, 'Note added');
  assert.equal(env.button.disabled, true);
  await env.click();
  assert.equal(env.requests.length, 1);
});

test('failed confirmation leaves the invoice unconfirmed and allows a safe retry', async () => {
  const env = environment({ ok: false, data: { message: 'Note could not be saved' } });
  await env.click();
  assert.equal(env.label.textContent, 'Not printed');
  assert.equal(env.status.textContent, 'Note could not be saved');
  assert.equal(env.button.disabled, false);
  assert.equal(env.button.textContent, 'Confirm printed');
});

test('malformed successful HTTP response does not claim that printing was confirmed', async () => {
  const env = environment({ data: {} });
  await env.click();
  assert.equal(env.label.textContent, 'Not printed');
  assert.equal(env.status.textContent, 'Failed');
  assert.equal(env.button.disabled, false);
});
