let active = null;

export function openModal({ title, bodyHtml, footerHtml, wide }) {
  closeModal();
  const el = document.createElement('div');
  el.className = 'modal-overlay';
  el.innerHTML = `
    <div class="modal-backdrop" data-close></div>
    <div class="modal-panel ${wide ? 'wide' : ''}" role="dialog" aria-modal="true">
      <div class="modal-header">
        <h2>${title}</h2>
        <button type="button" class="modal-close" data-close aria-label="Close">×</button>
      </div>
      <div class="modal-body">${bodyHtml}</div>
      ${footerHtml ? `<div class="modal-footer">${footerHtml}</div>` : ''}
    </div>
  `;
  document.body.appendChild(el);
  document.body.style.overflow = 'hidden';
  active = el;
  el.querySelectorAll('[data-close]').forEach((n) => n.addEventListener('click', closeModal));
  el.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });
  return el;
}

export function closeModal() {
  if (!active) return;
  active.remove();
  document.body.style.overflow = '';
  active = null;
}

export function confirm({ title = 'Confirm', message, confirmLabel = 'Confirm', danger = false }) {
  return new Promise((resolve) => {
    const el = openModal({
      title,
      bodyHtml: `<p style="margin:0;line-height:1.5">${message}</p>`,
      footerHtml: `
        <button type="button" class="btn btn-outline" data-cancel>Cancel</button>
        <button type="button" class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-ok>${confirmLabel}</button>
      `,
    });
    el.querySelector('[data-cancel]').addEventListener('click', () => { closeModal(); resolve(false); });
    el.querySelector('[data-ok]').addEventListener('click', () => { closeModal(); resolve(true); });
    // backdrop already closes — treat as cancel
    const orig = closeModal;
  });
}

export function formModal({ title, bodyHtml, submitLabel = 'Save', wide = false }) {
  return new Promise((resolve) => {
    const el = openModal({
      title,
      wide,
      bodyHtml: `<form id="modal-form">${bodyHtml}</form>`,
      footerHtml: `
        <button type="button" class="btn btn-outline" data-cancel>Cancel</button>
        <button type="submit" form="modal-form" class="btn btn-primary">${submitLabel}</button>
      `,
    });
    el.querySelector('[data-cancel]').addEventListener('click', () => { closeModal(); resolve(null); });
    el.querySelector('#modal-form').addEventListener('submit', (e) => {
      e.preventDefault();
      const data = Object.fromEntries(new FormData(e.target).entries());
      closeModal();
      resolve(data);
    });
  });
}
