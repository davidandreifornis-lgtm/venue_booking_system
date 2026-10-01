/**
 * Generic modal shell + confirm() + form() helpers.
 */

let active = null;

function buildShell({ title, bodyHtml, footerHtml, wide = false }) {
  const overlay = document.createElement('div');
  overlay.className = 'fixed inset-0 z-[90] flex items-end sm:items-center justify-center p-0 sm:p-4';
  overlay.innerHTML = `
    <div class="absolute inset-0 bg-ink/45 backdrop-blur-[3px]" data-modal-backdrop></div>
    <div class="relative w-full ${wide ? 'max-w-xl' : 'max-w-md'} bg-paper border border-rule shadow-card rounded-sm max-h-[90vh] flex flex-col sm:mx-0" role="dialog" aria-modal="true">
      <div class="flex items-center justify-between px-5 sm:px-6 py-4 border-b border-rule shrink-0">
        <h2 class="font-display text-[17px] font-medium tracking-[-0.02em] text-ink">${title}</h2>
        <button type="button" data-modal-close class="text-muted hover:text-ink p-1.5 -mr-1 rounded-sm hover:bg-rule/40 transition-colors duration-150" aria-label="Close">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="px-5 sm:px-6 py-5 overflow-y-auto flex-1 text-[13px] leading-relaxed">${bodyHtml}</div>
      ${footerHtml ? `<div class="px-5 sm:px-6 py-4 border-t border-rule flex items-center justify-end gap-2.5 shrink-0 bg-rule/10">${footerHtml}</div>` : ''}
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
      <button type="button" data-cancel class="px-3.5 py-2 text-[13px] text-muted hover:text-ink border border-rule rounded-sm transition-colors duration-150">Cancel</button>
      <button type="button" data-ok class="px-3.5 py-2 text-[13px] text-paper rounded-sm transition-colors duration-150 ${danger ? 'bg-clay hover:bg-clay/90' : 'bg-accent hover:bg-accent/90'}">${confirmLabel}</button>
    `;
    const el = openModal({
      title,
      bodyHtml: `<p class="text-ink leading-relaxed text-[14px]">${message}</p>`,
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
      <button type="button" data-cancel class="px-3.5 py-2 text-[13px] text-muted hover:text-ink border border-rule rounded-sm transition-colors duration-150">Cancel</button>
      <button type="submit" form="modal-form" class="px-3.5 py-2 text-[13px] text-paper bg-accent hover:bg-accent/90 rounded-sm transition-colors duration-150">${submitLabel}</button>
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
      const data = Object.fromEntries(fd.entries());
      closeModal();
      resolve(data);
    });
  });
}
