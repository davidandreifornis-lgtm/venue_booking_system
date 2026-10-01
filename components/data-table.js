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

    const thead = columns.map((c) => {
      const arrow = sortKey === c.key ? (sortDir === 1 ? ' ↑' : ' ↓') : '';
      const cls = c.sortable !== false ? ' style="cursor:pointer"' : '';
      return `<th data-sort="${escapeHtml(c.key)}"${cls}>${escapeHtml(c.label)}${arrow}</th>`;
    }).join('');

    const tbody = data.map((row, i) => {
      const cells = columns.map((c) => {
        const content = c.render ? c.render(row) : escapeHtml(row[c.key] ?? '—');
        return `<td>${content}</td>`;
      }).join('');
      const click = onRowClick ? ' clickable' : '';
      return `<tr class="${click}" data-row="${i}">${cells}</tr>`;
    }).join('');

    container.innerHTML = `
      <div class="table-responsive">
        <table class="table">
          <thead><tr>${thead}</tr></thead>
          <tbody>${tbody}</tbody>
        </table>
      </div>
    `;

    container.querySelectorAll('[data-sort]').forEach((th) => {
      th.addEventListener('click', () => {
        const key = th.getAttribute('data-sort');
        if (sortKey === key) sortDir *= -1;
        else { sortKey = key; sortDir = 1; }
        render();
      });
    });

    if (onRowClick) {
      container.querySelectorAll('[data-row]').forEach((tr) => {
        tr.addEventListener('click', () => {
          onRowClick(data[Number(tr.getAttribute('data-row'))]);
        });
      });
    }
  }
  render();
}
