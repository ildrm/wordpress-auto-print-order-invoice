'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../assets/js/admin.js'), 'utf8');

function environment({ restUrl = 'https://example.test/wp-json/wc-invoice-printer/v1', printers = [], printerId = '5', printerStates = {}, copies = '2', output = 'browser' } = {}) {
  const listeners = {};
  const requests = [];
  const opened = [];
  const notice = { className: '', textContent: '' };
  const hidden = { value: 'Saved' };
  class Option {
    constructor(text, value) { this.textContent = text; this.value = String(value); this.dataset = {}; }
  }
  const select = {
    options: [new Option('Saved', printerId)],
    selectedIndex: 0,
    get value() { return this.options[this.selectedIndex]?.value || ''; },
    set value(value) { this.selectedIndex = this.options.findIndex((option) => option.value === String(value)); },
    get selectedOptions() { return this.options[this.selectedIndex] ? [this.options[this.selectedIndex]] : []; },
    replaceChildren() { this.options = []; this.selectedIndex = 0; },
    add(option) { this.options.push(option); },
    dispatchEvent(event) { if (event.bubbles) listeners.change({ target: { ...this, id: 'wcip-printer-select', value: this.value, selectedOptions: this.selectedOptions } }); }
  };
  const dependent = { hidden: false };
  const auto = { checked: false, dispatchEvent(event) { if (event.bubbles) listeners.change({ target: { id: 'wcip-auto-enabled', checked: this.checked } }); } };
  const fields = {
    '.wcip-async-notice': notice,
    '#wcip-printer-select': select,
    '#wcip-printer-name': hidden,
    '#wcip-bulk_template': { value: 'classic' },
    '#wcip-bulk-output': { value: output },
    '#wcip-bulk_copies': { value: copies },
    '#wcip-auto-enabled': auto,
    '[data-auto-settings]': dependent
  };
  const root = { querySelector(selector) { return fields[selector]; }, addEventListener(name, callback) { listeners[name] = callback; } };
  const config = { restUrl, nonce: 'nonce', printerId, printerStates, strings: { working: 'Working', failed: 'Failed', noPrinters: 'No printers', selectPrinter: 'Choose a printer', submitted: 'Submitted', connectionOk: 'Connected', printersFound: '%d printers', jobsQueued: '%d queued', invalidCopies: 'Choose between 1 and 20 copies.' } };
  const context = { document: { querySelector() { return root; } }, window: { wcipAdmin: config, location: { reload() {} }, open(url) { opened.push(url); } }, wcipAdmin: config, URL, Option, Event: class { constructor(type, options = {}) { this.type = type; this.bubbles = !!options.bubbles; } }, fetch: async (url, options) => { requests.push({ url, options }); return { ok: true, async json() { return { printers, queued: 2 }; } }; } };
  vm.runInNewContext(source, context);
  async function click(dataset) {
    const button = { disabled: false, textContent: 'Start', dataset, setAttribute() {}, removeAttribute() {}, hasAttribute(name) { return name === 'data-wcip-bulk-print' && 'wcipBulkPrint' in dataset; } };
    await listeners.click({ target: { closest() { return button; } } });
    return button;
  }
  return { requests, opened, select, hidden, notice, dependent, click };
}

test('REST actions work with plain WordPress permalinks', async () => {
  const env = environment({ restUrl: 'https://example.test/?rest_route=/wc-invoice-printer/v1', printers: [{ id: '5', name: 'Saved' }] });
  await env.click({ wcipAction: 'refresh-printers' });
  const url = new URL(env.requests[0].url);
  assert.equal(url.searchParams.get('rest_route'), '/wc-invoice-printer/v1/printers');
  assert.equal(url.searchParams.get('refresh'), 'true');
  assert.equal(url.searchParams.get('_locale'), 'user');
  assert.equal(env.requests[0].options.headers['X-WP-Nonce'], 'nonce');
});

test('REST actions append correctly with pretty permalinks and trailing slash', async () => {
  const env = environment({ restUrl: 'https://example.test/wp-json/wc-invoice-printer/v1/' });
  await env.click({ wcipAction: 'test-connection' });
  assert.equal(new URL(env.requests[0].url).pathname, '/wp-json/wc-invoice-printer/v1/connection/test');
});

test('printer refresh preserves selected ID and complete printer names', async () => {
  const env = environment({ printers: [{ id: '1', name: 'Other' }, { id: '5', name: 'Kitchen · Left', state: 'online' }] });
  await env.click({ wcipAction: 'refresh-printers' });
  assert.equal(env.select.value, '5');
  assert.equal(env.hidden.value, 'Kitchen · Left');
});

test('printer states are translated without changing printer identity or saved names', async () => {
  const env = environment({ printers: [{ id: '5', name: 'Kitchen', state: 'online' }], printerStates: { online: 'آنلاین' } });
  await env.click({ wcipAction: 'refresh-printers' });
  assert.equal(env.select.selectedOptions[0].textContent, 'Kitchen · آنلاین');
  assert.equal(env.select.value, '5');
  assert.equal(env.hidden.value, 'Kitchen');
});

test('printer refresh does not silently select another device if saved device disappeared', async () => {
  const env = environment({ printers: [{ id: '1', name: 'Other' }] });
  await env.click({ wcipAction: 'refresh-printers' });
  assert.equal(env.select.value, '');
  assert.equal(env.hidden.value, '');
});

test('empty printer refresh clears stale printer name', async () => {
  const env = environment();
  await env.click({ wcipAction: 'refresh-printers' });
  assert.equal(env.hidden.value, '');
  assert.equal(env.notice.textContent, 'No printers');
});

test('PrintNode test printing requires an explicitly selected printer', async () => {
  const env = environment({ printerId: '' });
  await env.click({ wcipAction: 'test-print' });
  assert.equal(env.requests.length, 0);
  assert.equal(env.notice.textContent, 'Choose a printer');
});

test('disabled automatic printing hides dependent controls on initial page load', () => {
  assert.equal(environment().dependent.hidden, true);
});

test('bulk browser printing requests the print dialog and preserves copies', async () => {
  const env = environment();
  await env.click({ wcipBulkPrint: '', orderIds: '1,2', previewUrl: 'https://example.test/wp-admin/admin-post.php?action=wcip_preview&_wpnonce=nonce&order_ids=1,2' });
  const url = new URL(env.opened[0]);
  assert.equal(url.searchParams.get('print'), '1');
  assert.equal(url.searchParams.get('copies'), '2');
  assert.equal(env.requests.length, 0);
});

test('invalid copy count does not send print requests', async () => {
  const env = environment({ copies: '1.5', output: 'printnode' });
  await env.click({ wcipBulkPrint: '', orderIds: '1', previewUrl: 'https://example.test/wp-admin/admin-post.php' });
  assert.equal(env.requests.length, 0);
  assert.equal(env.notice.textContent, 'Choose between 1 and 20 copies.');
});
