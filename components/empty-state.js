import { escapeHtml } from '../js/utils.js';

export function emptyState({ title, hint, actionLabel, actionHref, actionId }) {
  let action = '';
  if (actionLabel && actionHref) {
    action = `<a href="${escapeHtml(actionHref)}" class="btn btn-primary">${escapeHtml(actionLabel)}</a>`;
  } else if (actionLabel && actionId) {
    action = `<button type="button" id="${escapeHtml(actionId)}" class="btn btn-primary">${escapeHtml(actionLabel)}</button>`;
  }
  return `
    <div class="empty-state">
      <div class="icon" aria-hidden="true">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
      </div>
      <h3>${escapeHtml(title)}</h3>
      ${hint ? `<p>${escapeHtml(hint)}</p>` : ''}
      ${action ? `<div>${action}</div>` : ''}
    </div>
  `;
}
export function renderEmpty(container, opts) {
  if (container) container.innerHTML = emptyState(opts);
}
