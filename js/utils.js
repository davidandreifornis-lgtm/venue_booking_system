/**
 * Shared helpers. Pure functions only.
 */

export function formatDate(value, opts = {}) {
  if (!value) return '—';
  const d = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(d.getTime())) return '—';
  const defaults = { year: 'numeric', month: 'short', day: 'numeric' };
  return d.toLocaleDateString('en-US', { ...defaults, ...opts });
}

export function formatTime(value) {
  if (!value) return '—';
  if (typeof value === 'string' && /^\d{1,2}:\d{2}/.test(value)) {
    const [h, m] = value.split(':').map(Number);
    const d = new Date();
    d.setHours(h, m, 0, 0);
    return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  }
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
}

export function formatDateTime(value) {
  if (!value) return '—';
  const d = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(d.getTime())) return '—';
  return `${formatDate(d)} ${formatTime(d)}`;
}

/**
 * Booking reference: VEN-YYYY-NNNNNN
 * Generate only when a request is actually created.
 */
export function formatRef(seq, year = new Date().getFullYear()) {
  const n = String(seq).padStart(6, '0');
  return `VEN-${year}-${n}`;
}

export function debounce(fn, ms = 200) {
  let t;
  return (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn(...args), ms);
  };
}

export function escapeHtml(str) {
  if (str == null) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

export function qs(sel, root = document) {
  return root.querySelector(sel);
}

export function qsa(sel, root = document) {
  return Array.from(root.querySelectorAll(sel));
}

export function parseQuery() {
  return Object.fromEntries(new URLSearchParams(window.location.search));
}
