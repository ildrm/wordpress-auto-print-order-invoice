(function () {
  'use strict';
  document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-wcip-confirm]');
    if (!button || button.disabled) return;
    const region = button.closest('.wcip-confirmation');
    const status = region.querySelector('[data-wcip-confirm-status]');
    const original = button.textContent;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.textContent = button.dataset.working;
    status.textContent = '';
    let confirmed = false;
    try {
      const endpoint = new URL(button.dataset.endpoint);
      endpoint.searchParams.set('_locale', 'user');
      const response = await fetch(endpoint.toString(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': button.dataset.nonce },
        body: JSON.stringify({ job_ids: button.dataset.jobIds.split(',').map(Number) })
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.printed !== true) throw new Error(data.message || button.dataset.failed);
      confirmed = true;
      button.textContent = button.dataset.printed;
      status.textContent = button.dataset.success;
      const container = button.closest('[data-wcip-print-record]');
      const label = container?.querySelector('[data-wcip-printed-label]');
      if (label) label.textContent = button.dataset.printed;
    } catch (error) {
      status.textContent = error.message || button.dataset.failed;
    } finally {
      button.removeAttribute('aria-busy');
      if (!confirmed) { button.disabled = false; button.textContent = original; }
    }
  });
})();
