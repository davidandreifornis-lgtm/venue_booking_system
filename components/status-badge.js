const MAP = {
  'Pending Manager Validation': 'badge badge-pending',
  'Manager Validated': 'badge badge-pending',
  'Pending HR Decision': 'badge badge-pending',
  Approved: 'badge badge-approved',
  Declined: 'badge badge-declined',
  Rescheduled: 'badge badge-rescheduled',
  Cancelled: 'badge badge-cancelled',
};

export function statusBadge(status) {
  const cls = MAP[status] || 'badge badge-pending';
  return `<span class="${cls}">${escape(status || '—')}</span>`;
}
export function getStatusBadge(status) {
  return statusBadge(status);
}
function escape(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
