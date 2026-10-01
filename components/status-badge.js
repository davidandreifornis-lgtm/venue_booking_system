/**
 * Single source of truth for booking status → badge classes.
 */

const MAP = {
  'Pending Manager Validation': 'border border-rule/80 text-muted bg-rule/15',
  'Manager Validated': 'border border-rule/80 text-muted bg-rule/15',
  'Pending HR Decision': 'border border-dashed border-muted/40 text-muted bg-transparent',
  Approved: 'bg-accent-soft text-accent border border-accent/15',
  Declined: 'bg-clay/8 text-clay border border-clay/20',
  Rescheduled: 'bg-clay/8 text-clay border border-clay/20',
  Cancelled: 'bg-rule/30 text-muted border border-rule',
};

export function statusBadge(status) {
  const classes = MAP[status] || 'border border-rule text-muted bg-transparent';
  return `<span class="inline-flex items-center px-2 py-0.5 text-[11px] font-medium tracking-[0.04em] rounded-sm ${classes}">${escape(status || '—')}</span>`;
}

function escape(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

export function statusClasses(status) {
  return MAP[status] || MAP['Pending Manager Validation'];
}
