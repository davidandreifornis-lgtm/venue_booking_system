const MAP = {
  'Pending Manager Validation': 'badge badge-pending',
  'Manager Validated': 'badge badge-pending',
  'Pending HR Decision': 'badge badge-pending',
  Approved: 'badge badge-approved',
  Declined: 'badge badge-declined',
  Rescheduled: 'badge badge-rescheduled',
  Cancelled: 'badge badge-cancelled',
  Completed: 'badge badge-done',
};

const parse = (d, t) => {
  const [h, m] = String(t || '').split(':');
  const dt = new Date(String(d).slice(0, 10) + 'T00:00:00');
  dt.setHours(Number(h) || 0, Number(m) || 0, 0, 0);
  return dt;
};

/**
 * 'done' | 'live' | null for a booking, using the browser clock.
 * Approved/Rescheduled bookings become 'live' during their time slot and
 * 'done' once it has ended (the server also flips them to Completed).
 */
export function bookingPhase(b, now = new Date()) {
  if (!b) return null;
  if (b.status === 'Completed') return 'done';
  if (b.status !== 'Approved' && b.status !== 'Rescheduled') return null;
  if (!b.date || !b.startTime || !b.endTime) return null;
  if (now >= parse(b.date, b.endTime)) return 'done';
  if (now >= parse(b.date, b.startTime)) return 'live';
  return null;
}

/** Accepts a status string, or a booking object (preferred: adds Done / In progress). */
export function statusBadge(arg) {
  const b = arg && typeof arg === 'object' ? arg : null;
  const status = b ? b.status : arg;
  const phase = bookingPhase(b);
  if (phase === 'live') return '<span class="badge badge-live"><span class="live-dot"></span>In progress</span>';
  if (phase === 'done') return '<span class="badge badge-done">Done</span>';
  const cls = MAP[status] || 'badge badge-pending';
  return `<span class="${cls}">${escape(status || '—')}</span>`;
}
export function getStatusBadge(arg) {
  return statusBadge(arg);
}
function escape(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
