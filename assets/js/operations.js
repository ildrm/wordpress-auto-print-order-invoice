(function () {
  'use strict';
  const config = window.wcipOperations;
  if (!config) return;
  const root = document.querySelector('[data-wcip-operations]');
  if (!root) return;
  const query = (name) => root.querySelector(`[data-wcip-${name}]`);
  const status = query('operations-status');
  let preview = null;
  let scanned = null;
  let busy = false;
  let lastScan = '';
  let lastScanAt = 0;
  const identity = () => window.crypto.randomUUID ? window.crypto.randomUUID() : Array.from(window.crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, '0')).join('');
  const request = async (path, body) => {
    const base = config.rest.replace(/\/$/, '');
    const url = base + path;
    const response = await fetch(url, {
      method: body === undefined ? 'GET' : 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce},
      body: body === undefined ? undefined : JSON.stringify(body)
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || config.failed);
    return data;
  };
  const run = async (callback) => {
    if (busy) return;
    busy = true;
    status.textContent = config.working;
    root.setAttribute('aria-busy', 'true');
    try { await callback(); status.textContent = config.complete; }
    catch (error) { status.textContent = error.message || config.failed; }
    finally { busy = false; root.removeAttribute('aria-busy'); }
  };
  const bind = (name, callback) => { const button = query(name); if (button) button.addEventListener('click', () => run(callback)); };
  const utc = (value, end) => Math.floor(Date.parse(value + (end ? 'T23:59:59Z' : 'T00:00:00Z')) / 1000);
  const showPreview = (data) => {
    preview = data;
    const list = query('missing-list');
    list.replaceChildren();
    data.items.forEach((row) => {
      const label = document.createElement('label');
      label.style.display = 'block';
      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox'; checkbox.value = row.order_id;
      checkbox.disabled = !row.eligible || Boolean(row.existing_job_id);
      checkbox.setAttribute('data-wcip-missing-order', '');
      label.append(checkbox, document.createTextNode(` #${row.number} — ${row.existing_job_id ? config.existing + ' #' + row.existing_job_id + ' (' + row.status + ')' : row.eligible ? config.notPrinted : config.ineligible}`));
      list.append(label);
    });
    if (data.next_page) {
      const next = document.createElement('button'); next.type = 'button'; next.className = 'button'; next.textContent = String(data.next_page);
      next.setAttribute('aria-label', String(data.next_page));
      next.addEventListener('click', () => run(async () => showPreview(await request('/reconciliation/preview', {from: data.from, to: data.to, page: data.next_page}))));
      list.append(next);
    }
    query('enqueue-missing').disabled = false;
  };
  bind('preview-missing', async () => showPreview(await request('/reconciliation/preview', {from: utc(query('from').value, false), to: utc(query('to').value, true), page: 1})));
  bind('enqueue-missing', async () => {
    if (!preview || !query('backfill-confirm').checked) throw new Error(config.failed);
    const ids = Array.from(root.querySelectorAll('[data-wcip-missing-order]:checked')).map((input) => Number(input.value));
    const data = await request('/reconciliation/enqueue', {order_ids: ids, preview_token: preview.preview_token, confirm: true});
    query('missing-list').textContent = JSON.stringify(data, null, 2);
    query('enqueue-missing').disabled = true; preview = null;
  });
  const showScan = () => {
    const target = query('scan-result'); target.replaceChildren();
    const heading = document.createElement('h3'); heading.textContent = `${config.order} #${scanned.data.order_number}`; target.append(heading);
    const table = document.createElement('table'); table.className = 'widefat striped';
    const head = document.createElement('thead'); const headers = document.createElement('tr');
    [config.item, config.sku, config.quantity, config.weight].forEach((label) => { const th = document.createElement('th'); th.scope = 'col'; th.textContent = label; headers.append(th); });
    head.append(headers); table.append(head); const body = document.createElement('tbody');
    scanned.data.items.forEach((item) => { const row = document.createElement('tr'); [item.name, item.sku || config.unknown, item.quantity, item.weight.line_kg === null ? config.unknown : item.weight.line_kg].forEach((value) => { const cell = document.createElement('td'); cell.textContent = String(value); row.append(cell); }); body.append(row); });
    table.append(body); target.append(table);
  };
  const scan = async () => {
    const reference = query('scan').value.trim();
    if (reference === lastScan && Date.now() - lastScanAt < 1500) return;
    lastScan = reference; lastScanAt = Date.now();
    scanned = null; query('stage-submit').disabled = true;
    if (query('export-submit')) query('export-submit').disabled = true;
    query('scan-result').replaceChildren();
    scanned = await request('/scan', {reference});
    showScan();
    query('stage').value = scanned.fulfillment.stage;
    query('stage-submit').disabled = false;
    if (query('export-submit')) query('export-submit').disabled = false;
  };
  bind('scan-submit', scan);
  if (query('scan')) query('scan').addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); run(scan); } });
  bind('stage-submit', async () => {
    scanned.fulfillment = await request('/fulfillment/' + scanned.data.order_id, {stage: query('stage').value, revision: Number(scanned.fulfillment.revision), event_key: identity()});
    showScan();
  });
  const exportEvents = new Map();
  bind('export-submit', async () => {
    const order = Number(scanned.data.order_id);
    if (!exportEvents.has(order)) exportEvents.set(order, identity());
    const exportEvent = exportEvents.get(order);
    query('export-result').textContent = JSON.stringify(await request('/exports', {order_id: Number(scanned.data.order_id), event_key: exportEvent}), null, 2);
  });
  bind('export-test', async () => { query('export-result').textContent = JSON.stringify(await request('/exports/test', {}), null, 2); });
  bind('export-history', async () => { query('export-result').textContent = JSON.stringify(await request('/exports/history'), null, 2); });
  bind('diagnostics', async () => { query('diagnostics-result').textContent = JSON.stringify(await request('/diagnostics'), null, 2); });
}());
