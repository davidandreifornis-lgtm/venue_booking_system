/**
 * Transient stacked toasts, top-right.
 */

let root = null;

function ensureRoot() {
  if (root) return root;
  root = document.createElement('div');
  root.id = 'toast-root';
  root.className = 'fixed top-4 right-4 z-[100] flex flex-col gap-2 pointer-events-none max-w-[calc(100vw-2rem)]';
  document.body.appendChild(root);
  return root;
}

export function toast(message, type = 'info', duration = 4200) {
  const el = document.createElement('div');
  const border =
    type === 'error' ? 'border-clay/35 bg-paper text-ink' :
    type === 'success' ? 'border-accent/30 bg-paper text-ink' :
    'border-rule bg-paper text-ink';

  const accentBar =
    type === 'error' ? 'bg-clay' :
    type === 'success' ? 'bg-accent' :
    'bg-rule';

  el.className = `pointer-events-auto w-full max-w-sm border ${border} shadow-card rounded-sm overflow-hidden transition-opacity duration-200 opacity-0 flex`;
  el.innerHTML = `
    <div class="w-[3px] shrink-0 ${accentBar}"></div>
    <div class="flex-1 px-4 py-3.5 flex items-start gap-3">
      <span class="flex-1 text-[13px] leading-snug text-ink">${escape(message)}</span>
      <button type="button" class="text-muted hover:text-ink shrink-0 -mt-0.5 p-0.5" aria-label="Dismiss">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
      </button>
    </div>
  `;

  ensureRoot().appendChild(el);
  requestAnimationFrame(() => { el.style.opacity = '1'; });

  const remove = () => {
    el.style.opacity = '0';
    setTimeout(() => el.remove(), 200);
  };

  el.querySelector('button').addEventListener('click', remove);
  setTimeout(remove, duration);
}

function escape(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}
