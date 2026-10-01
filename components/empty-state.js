/**
 * Single empty-state component for the entire application.
 */

import { escapeHtml } from '../js/utils.js';
import { ui } from '../js/ui.js';

/**
 * @param {object} opts
 * @param {string} opts.title
 * @param {string} [opts.hint]
 * @param {string} [opts.actionLabel]
 * @param {string} [opts.actionHref]
 * @param {string} [opts.actionId]
 */
export function emptyState({ title, hint, actionLabel, actionHref, actionId }) {
  let action = '';
  if (actionLabel && actionHref) {
    action = `<a href="${escapeHtml(actionHref)}" class="${ui.btnPrimary} mt-6">${escapeHtml(actionLabel)}</a>`;
  } else if (actionLabel && actionId) {
    action = `<button type="button" id="${escapeHtml(actionId)}" class="${ui.btnPrimary} mt-6">${escapeHtml(actionLabel)}</button>`;
  }

  return `
    <div class="flex flex-col items-start py-12 sm:py-14">
      <div class="h-px w-10 bg-rule mb-6"></div>
      <h3 class="font-display text-xl sm:text-2xl font-medium tracking-[-0.03em] text-ink leading-snug max-w-md">${escapeHtml(title)}</h3>
      ${hint ? `<p class="mt-2 text-sm text-muted leading-relaxed max-w-sm">${escapeHtml(hint)}</p>` : ''}
      ${action}
    </div>
  `;
}

export function renderEmpty(container, opts) {
  if (!container) return;
  container.innerHTML = emptyState(opts);
}
