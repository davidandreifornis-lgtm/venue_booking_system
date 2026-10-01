/**
 * Typographic empty state — calm, premium, no illustrations.
 */

import { escapeHtml } from '../js/utils.js';

export function emptyState({ title, hint, actionLabel, actionHref, actionId }) {
  const action = actionLabel
    ? actionHref
      ? `<a href="${escapeHtml(actionHref)}" class="mt-6 inline-flex items-center gap-2 text-[13px] font-medium text-accent hover:text-accent/80 tracking-wide transition-colors duration-150">
           ${escapeHtml(actionLabel)}
           <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
         </a>`
      : `<button type="button" id="${escapeHtml(actionId || '')}" class="mt-6 inline-flex items-center gap-2 text-[13px] font-medium text-accent hover:text-accent/80 tracking-wide transition-colors duration-150">
           ${escapeHtml(actionLabel)}
           <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
         </button>`
    : '';

  return `
    <div class="empty-state flex flex-col items-start py-14 px-1 sm:px-2">
      <div class="h-px w-12 bg-rule mb-8"></div>
      <h3 class="font-display text-[22px] sm:text-2xl font-medium tracking-[-0.03em] text-ink leading-snug max-w-md">${escapeHtml(title)}</h3>
      ${hint ? `<p class="mt-3 text-[13px] text-muted leading-relaxed max-w-sm">${escapeHtml(hint)}</p>` : ''}
      ${action}
    </div>
  `;
}

export function renderEmpty(container, opts) {
  if (!container) return;
  container.innerHTML = emptyState(opts);
}
