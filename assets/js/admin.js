(function () {
  'use strict';
  const root = document.querySelector('.wcip-admin');
  if (!root || !window.wcipAdmin) return;
  const notice = root.querySelector('.wcip-async-notice');
  const request = async (path, options = {}) => {
    // WordPress uses ?rest_route= when pretty permalinks are disabled.
    const endpoint = new URL(wcipAdmin.restUrl);
    const suffix = new URL(path, endpoint.origin);
    if (endpoint.searchParams.has('rest_route')) {
      endpoint.searchParams.set('rest_route', endpoint.searchParams.get('rest_route').replace(/\/$/, '') + suffix.pathname);
    } else {
      endpoint.pathname = endpoint.pathname.replace(/\/$/, '') + suffix.pathname;
    }
    suffix.searchParams.forEach((value, key) => endpoint.searchParams.set(key, value));
    endpoint.searchParams.set('_locale', 'user');
    const response = await fetch(endpoint.toString(), {
      ...options,
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wcipAdmin.nonce, ...(options.headers || {}) }
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || wcipAdmin.strings.failed);
    return data;
  };
  const setNotice = (message, type = '') => {
    if (!notice) return;
    notice.className = 'wcip-async-notice ' + (type ? 'is-' + type : '');
    notice.textContent = message;
  };
  root.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-wcip-action], [data-wcip-job], [data-wcip-bulk-print]');
    if (!button || button.disabled) return;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    const original = button.textContent;
    button.textContent = wcipAdmin.strings.working;
    try {
      if (button.dataset.wcipAction === 'test-connection') {
        await request('/connection/test', { method: 'POST' });
        setNotice(wcipAdmin.strings.connectionOk, 'success');
      } else if (button.dataset.wcipAction === 'refresh-printers') {
        const data = await request('/printers?refresh=true');
        const select = root.querySelector('#wcip-printer-select');
        const selectedId = select.value || wcipAdmin.printerId;
        select.replaceChildren();
        if (!data.printers.length) {
          select.add(new Option(wcipAdmin.strings.noPrinters, ''));
          setNotice(wcipAdmin.strings.noPrinters, 'warning');
        } else {
          select.add(new Option(wcipAdmin.strings.selectPrinter, ''));
          data.printers.forEach((printer) => {
            const state = Object.prototype.hasOwnProperty.call(wcipAdmin.printerStates || {}, printer.state)
              ? wcipAdmin.printerStates[printer.state] : printer.state;
            const option = new Option(printer.name + (state ? ' · ' + state : ''), printer.id);
            option.dataset.printerName = printer.name;
            select.add(option);
          });
          if (data.printers.some((printer) => String(printer.id) === selectedId)) select.value = selectedId;
          setNotice(wcipAdmin.strings.printersFound.replace('%d', data.printers.length), 'success');
        }
        select.dispatchEvent(new Event('change', { bubbles: true }));
      } else if (button.dataset.wcipAction === 'test-print') {
        const printerId = root.querySelector('#wcip-printer-select').value;
        if (!printerId) throw new Error(wcipAdmin.strings.selectPrinter);
        await request('/test-print', { method: 'POST', body: JSON.stringify({ printer_id: printerId }) });
        setNotice(wcipAdmin.strings.submitted, 'success');
      } else if (button.dataset.wcipJob) {
        await request('/jobs/' + button.dataset.jobId + '/' + button.dataset.wcipJob, { method: 'POST' });
        window.location.reload();
      } else if (button.hasAttribute('data-wcip-bulk-print')) {
        const orderIds = button.dataset.orderIds.split(',').map(Number);
        const templateId = root.querySelector('#wcip-bulk_template').value;
        const output = root.querySelector('#wcip-bulk-output').value;
        const copies = Number(root.querySelector('#wcip-bulk_copies').value);
        if (!Number.isInteger(copies) || copies < 1 || copies > 20) throw new Error(wcipAdmin.strings.invalidCopies);
        if (output === 'browser') {
          const url = new URL(button.dataset.previewUrl);
          url.searchParams.set('template', templateId);
          url.searchParams.set('copies', String(copies));
          url.searchParams.set('print', '1');
          window.open(url.toString(), '_blank', 'noopener');
        } else {
          const data = await request('/print', { method: 'POST', body: JSON.stringify({ order_ids: orderIds, template_id: templateId, provider_id: 'printnode', printer_id: wcipAdmin.printerId, copies }) });
          setNotice(wcipAdmin.strings.jobsQueued.replace('%d', data.queued), 'success');
        }
      }
    } catch (error) {
      setNotice(error.message, 'error');
    } finally {
      button.disabled = false;
      button.removeAttribute('aria-busy');
      button.textContent = original;
    }
  });
  root.addEventListener('change', (event) => {
    if (event.target.id === 'wcip-printer-select') {
      const hidden = root.querySelector('#wcip-printer-name');
      const selected = event.target.selectedOptions[0];
      hidden.value = event.target.value ? (selected?.dataset.printerName || selected?.textContent || '') : '';
    }
    if (event.target.id === 'wcip-auto-enabled') {
      const dependent = root.querySelector('[data-auto-settings]');
      dependent.hidden = !event.target.checked;
    }
  });
  const auto = root.querySelector('#wcip-auto-enabled');
  if (auto) auto.dispatchEvent(new Event('change', { bubbles: true }));
})();
