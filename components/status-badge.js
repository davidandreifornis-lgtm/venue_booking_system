/**
 * Single source of truth for status badges.
 * Import statusBadge() everywhere — never hardcode badge classes.
 */

const MAP = {
  'Pending Manager Validation': 'border border-rule bg-rule/20 text-muted',
  'Manager Validated': 'border border-rule bg-rule/20 text-muted',
  'Pending HR Decision': 'border border-dashed border-muted/50 bg-transparent text-muted',
  Approved: 'border border-accent/20 bg-accent-soft text-accent',
  Declined: 'border border-clay/25 bg-clay/10 text-clay',
  Rescheduled: 'border border-clay/25 bg-clay/10 text-clay',
  Cancelled: 'border border-rule bg-rule/30 text-muted',
};

export function statusBadge(status) {
  const classes = MAP[status] || 'border border-rule bg-rule/20 text-muted';
  return `<span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium tracking-[0.03em] ${classes}">${escape(status || '—')}</span>`;
}

export function getStatusBadge(status) {
  return statusBadge(status);
}

export function statusClasses(status) {
  return MAP[status] || MAP['Pending Manager Validation'];
}

function escape(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}
