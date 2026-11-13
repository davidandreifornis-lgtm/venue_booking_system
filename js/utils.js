/**
 * Shared helpers. Pure functions only.
 */

/**
 * Company time zone. Times the server records for events (created, updated, notified, logged...) are stored in the
 * database as UTC and are shown here in this zone for every user, whatever their device's clock is set to.
 * Keep it equal to 'timezone' in backend/config/config.php.
 */
export const APP_TIMEZONE = 'Asia/Manila';

export function formatDate(value, opts = {}) {
  if (!value) return '—';
  const d = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(d.getTime())) return '—';
  const defaults = { year: 'numeric', month: 'short', day: 'numeric' };
  // A bare calendar date (YYYY-MM-DD, e.g. a booking date) is parsed as UTC midnight: format it as UTC so it can never
  // slip to the previous day on a device west of UTC.
  const dateOnly = typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value);
  return d.toLocaleDateString('en-US', { ...defaults, ...(dateOnly ? { timeZone: 'UTC' } : {}), ...opts });
}

/**
 * Parses a server timestamp. The API sends either ISO with "Z" or the database's plain "YYYY-MM-DD HH:MM:SS" - which
 * is UTC but carries no zone, so the browser would wrongly read it as local time. Treat zone-less values as UTC.
 */
function parseServerTimestamp(value) {
  if (value instanceof Date) return value;
  if (typeof value === 'string') {
    const m = value.trim().match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)(Z|[+-]\d{2}:?\d{2})?$/);
    if (m) {
      const time = m[2].length === 5 ? `${m[2]}:00` : m[2];
      return new Date(`${m[1]}T${time}${m[3] || 'Z'}`);
    }
  }
  return new Date(value);
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
  const d = parseServerTimestamp(value);
  if (Number.isNaN(d.getTime())) return '—';
  const date = d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric', timeZone: APP_TIMEZONE });
  const time = d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', timeZone: APP_TIMEZONE });
  return `${date} ${time}`;
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
