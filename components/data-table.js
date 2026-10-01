/**
 * Sortable, responsive data table with empty-state fallback.
 */

import { emptyState } from './empty-state.js';
import { escapeHtml } from '../js/utils.js';

export function dataTable({ container, columns, rows, empty, onRowClick }) {
  if (!container) return;

  if (!rows || rows.length === 0) {
    container.innerHTML = emptyState(empty || { title: 'No records found.' });
    return;
  }

  let sortKey = null;
  let sortDir = 1;

  function render() {
    let data = [...rows];
    if (sortKey) {
      data.sort((a, b) => {
        const va = a[sortKey] ?? '';
        const vb = b[sortKey] ?? '';
        if (va < vb) return -1 * sortDir;
        if (va > vb) return 1 * sortDir;
        return 0;
      });
    }

    const thead = columns
      .map((c) => {
        const arrow =
          sortKey === c.key
            ? `<span class="ml-1 opacity-60">${sortDir === 1 ? '↑' : '↓'}</span>`
            : '';
        const cls = c.sortable !== false ? 'cursor-pointer select-none hover:text-ink' : '';
        return `<th class="px-4 py-3.5 text-left text-[10px] font-medium uppercase tracking-[0.1em] text-muted border-b border-rule whitespace-nowrap ${cls}" data-sort="${escapeHtml(c.key)}">${escapeHtml(c.label)}${arrow}</th>`;
      })
      .join('');

    const tbody = data
      .map((row, i) => {
        const cells = columns
          .map((c) => {
            const content = c.render ? c.render(row) : escapeHtml(row[c.key] ?? '—');
            return `<td class="px-4 py-3.5 text-[13px] text-ink border-b border-rule/70 whitespace-nowrap">${content}</td>`;
          })
          .join('');
        const click = onRowClick ? 'cursor-pointer hover:bg-rule/25 transition-colors duration-100' : '';
        return `<tr class="${click}" data-row="${i}">${cells}</tr>`;
      })
      .join('');

    container.innerHTML = `
      <div class="border border-rule rounded-sm overflow-hidden bg-paper">
        <div class="overflow-x-auto">
          <table class="w-full min-w-[640px] text-sm">
            <thead class="bg-rule/20">
              <tr>${thead}</tr>
            </thead>
            <tbody>${tbody}</tbody>
          </table>
        </div>
      </div>
    `;

    container.querySelectorAll('[data-sort]').forEach((th) => {
      th.addEventListener('click', () => {
        const key = th.getAttribute('data-sort');
        if (sortKey === key) sortDir *= -1;
        else {
          sortKey = key;
          sortDir = 1;
        }
        render();
      });
    });

    if (onRowClick) {
      container.querySelectorAll('[data-row]').forEach((tr) => {
        tr.addEventListener('click', () => {
          const idx = Number(tr.getAttribute('data-row'));
          onRowClick(data[idx]);
        });
      });
    }
  }

  render();
}
