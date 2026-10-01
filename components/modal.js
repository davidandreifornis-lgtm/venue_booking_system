/**
 * Standard modal shell — same chrome everywhere.
 */

let active = null;

function buildShell({ title, bodyHtml, footerHtml, wide = false }) {
  const overlay = document.createElement('div');
  overlay.className = 'fixed inset-0 z-[90] flex items-end sm:items-center justify-center p-0 sm:p-4';
  overlay.innerHTML = `
    <div class="absolute inset-0 bg-ink/40 backdrop-blur-[2px]" data-modal-backdrop></div>
    <div class="relative w-full ${wide ? 'max-w-xl' : 'max-w-lg'} bg-paper border border-rule shadow-card rounded max-h-[90vh] flex flex-col" role="dialog" aria-modal="true">
      <div class="flex items-center justify-between h-14 px-5 sm:px-6 border-b border-rule shrink-0">
        <h2 class="font-display text-lg font-medium tracking-[-0.02em] text-ink">${title}</h2>
        <button type="button" data-modal-close class="flex items-center justify-center w-8 h-8 rounded text-muted hover:text-ink hover:bg-rule/50 transition-colors duration-150" aria-label="Close">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="px-5 sm:px-6 py-5 overflow-y-auto flex-1 text-sm">${bodyHtml}</div>
      ${footerHtml ? `<div class="px-5 sm:px-6 py-4 border-t border-rule flex items-center justify-end gap-2 shrink-0">${footerHtml}</div>` : ''}
    </div>
  `;
  return overlay;
}

export function openModal({ title, bodyHtml, footerHtml, wide, onClose }) {
  closeModal();
  const el = buildShell({ title, bodyHtml, footerHtml, wide });
  document.body.appendChild(el);
  document.body.style.overflow = 'hidden';
  active = { el, onClose };

  const close = () => closeModal();
  el.querySelector('[data-modal-backdrop]').addEventListener('click', close);
  el.querySelector('[data-modal-close]').addEventListener('click', close);
  el.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') close();
  });

  const focusable = el.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
  if (focusable) focusable.focus();
  return el;
}

export function closeModal() {
  if (!active) return;
  active.el.remove();
  document.body.style.overflow = '';
  if (typeof active.onClose === 'function') active.onClose();
  active = null;
}

export function confirm({ title = 'Confirm', message, confirmLabel = 'Confirm', danger = false }) {
  return new Promise((resolve) => {
    const footer = `
      <button type="button" data-cancel class="inline-flex items-center justify-center rounded border border-rule bg-paper px-4 py-2 text-sm font-medium text-ink hover:bg-rule/40 transition-colors duration-150">Cancel</button>
      <button type="button" data-ok class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium text-paper transition-colors duration-150 ${danger ? 'bg-clay hover:bg-clay/90' : 'bg-accent hover:bg-accent/90'}">${confirmLabel}</button>
    `;
    const el = openModal({
      title,
      bodyHtml: `<p class="text-sm text-ink leading-relaxed">${message}</p>`,
      footerHtml: footer,
      onClose: () => resolve(false),
    });
    el.querySelector('[data-cancel]').addEventListener('click', () => {
      closeModal();
      resolve(false);
    });
    el.querySelector('[data-ok]').addEventListener('click', () => {
      closeModal();
      resolve(true);
    });
  });
}

export function formModal({ title, bodyHtml, submitLabel = 'Save', wide = false }) {
  return new Promise((resolve) => {
    const footer = `
      <button type="button" data-cancel class="inline-flex items-center justify-center rounded border border-rule bg-paper px-4 py-2 text-sm font-medium text-ink hover:bg-rule/40 transition-colors duration-150">Cancel</button>
      <button type="submit" form="modal-form" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium text-paper bg-accent hover:bg-accent/90 transition-colors duration-150">${submitLabel}</button>
    `;
    const el = openModal({
      title,
      bodyHtml: `<form id="modal-form" class="space-y-4">${bodyHtml}</form>`,
      footerHtml: footer,
      wide,
      onClose: () => resolve(null),
    });
    el.querySelector('[data-cancel]').addEventListener('click', () => {
      closeModal();
      resolve(null);
    });
    el.querySelector('#modal-form').addEventListener('submit', (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      closeModal();
      resolve(Object.fromEntries(fd.entries()));
    });
  });
}
