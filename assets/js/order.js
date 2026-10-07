(function () {
  'use strict';
  const config = window.wcipOrderPrint;
  if (!config) return;
  document.addEventListener('click', async (event) => {
    const open = event.target.closest('[data-wcip-open-print]');
    if (open) {
      const dialog = [...document.querySelectorAll('.wcip-order-dialog')].find((item) => item.dataset.orderId === open.dataset.wcipOpenPrint);
      if (dialog && !dialog.open) dialog.showModal();
      return;
    }
    const dialog = event.target.closest('.wcip-order-dialog');
    if (!dialog) return;
    const preview = event.target.closest('[data-wcip-preview]');
    const submit = event.target.closest('[data-wcip-submit]');
    if ((!preview && !submit) || submit?.disabled) return;
    const template = dialog.querySelector('#wcip-order-template').value;
    const output = dialog.querySelector('#wcip-order-output').value;
    const copies = Number(dialog.querySelector('#wcip-order-copies').value);
    const status = dialog.querySelector('[data-wcip-print-status]');
    if (!Number.isInteger(copies) || copies < 1 || copies > 20) {
      status.textContent = config.invalidCopies;
      return;
    }
    if (preview || output === 'browser') {
      const url = new URL(dialog.dataset.previewBase);
      url.searchParams.set('template', template);
      url.searchParams.set('copies', String(preview ? 1 : copies));
      if (preview) url.searchParams.set('preview', '1');
      else url.searchParams.set('print', '1');
      window.open(url.toString(), '_blank', 'noopener');
      status.textContent = '';
      return;
    }
    submit.disabled = true;
    submit.setAttribute('aria-busy', 'true');
    status.textContent = config.sending;
    try {
      const endpoint = new URL(config.rest);
      endpoint.searchParams.set('_locale', 'user');
      const response = await fetch(endpoint.toString(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
        body: JSON.stringify({ order_ids: [Number(dialog.dataset.orderId)], template_id: template, provider_id: 'printnode', printer_id: config.printer, copies })
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.message || config.failed);
      status.textContent = config.queued;
    } catch (error) {
      status.textContent = error.message || config.failed;
    } finally {
      submit.disabled = false;
      submit.removeAttribute('aria-busy');
    }
  });
  document.addEventListener('change', (event) => {
    if (event.target.id !== 'wcip-order-output') return;
    const dialog = event.target.closest('.wcip-order-dialog');
    if (dialog) dialog.querySelector('[data-wcip-print-help]').textContent = event.target.value === 'browser' ? config.browserHelp : config.nodeHelp;
  });
})();
