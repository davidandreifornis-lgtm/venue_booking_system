/**
 * Skeleton rows for tables, spinner for cards.
 */

export function skeletonRows(count = 4, cols = 5) {
  const cells = Array.from({ length: cols }, () =>
    '<td class="px-4 py-3"><div class="h-3 bg-rule/60 rounded-sm animate-pulse w-3/4"></div></td>'
  ).join('');
  const rows = Array.from({ length: count }, () => `<tr class="border-b border-rule">${cells}</tr>`).join('');
  return rows;
}

export function skeletonCards(count = 3) {
  return Array.from({ length: count }, () => `
    <div class="border border-rule rounded-sm p-5 shadow-card bg-paper">
      <div class="h-3 w-16 bg-rule/60 rounded-sm animate-pulse mb-4"></div>
      <div class="h-8 w-12 bg-rule/60 rounded-sm animate-pulse"></div>
    </div>
  `).join('');
}

export function spinner(size = 20) {
  return `
    <svg class="animate-spin text-muted" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v3a5 5 0 00-5 5H4z"></path>
    </svg>
  `;
}

export function renderLoading(container, type = 'table', opts = {}) {
  if (!container) return;
  if (type === 'cards') {
    container.innerHTML = `<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">${skeletonCards(opts.count || 3)}</div>`;
  } else if (type === 'spinner') {
    container.innerHTML = `<div class="flex items-center justify-center py-16">${spinner(28)}</div>`;
  } else {
    container.innerHTML = `
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <tbody>${skeletonRows(opts.count || 4, opts.cols || 5)}</tbody>
        </table>
      </div>
    `;
  }
}
