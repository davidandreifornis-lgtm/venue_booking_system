/**
 * Booking request vertical timeline.
 */

import { formatDateTime, escapeHtml } from '../js/utils.js';

const STAGES = [
  { key: 'submitted', label: 'Request Submitted' },
  { key: 'manager_validation', label: 'Manager Validation' },
  { key: 'manager_validated', label: 'Manager Validated' },
  { key: 'hr_review', label: 'HR Review' },
  { key: 'final', label: 'Final Decision' },
];

/**
 * Build timeline from a booking record.
 * Completed stages show status, timestamp, actor, notes.
 * Future stages are greyed with outlined dots.
 */
export function timeline(booking) {
  if (!booking) return '';

  const status = booking.status || '';
  const events = [];

  // Submitted
  events.push({
    label: 'Request Submitted',
    done: true,
    timestamp: booking.createdAt || booking.createdDate,
    actor: booking.requesterName || booking.requester,
    notes: null,
    tone: 'accent',
  });

  // Manager path
  if (['Manager Validated', 'Pending HR Decision', 'Approved', 'Declined', 'Rescheduled'].includes(status)) {
    events.push({
      label: 'Manager Validated',
      done: true,
      timestamp: booking.managerValidatedAt,
      actor: booking.managerName || booking.departmentManager,
      notes: booking.managerNotes,
      tone: 'accent',
    });
  } else if (status === 'Pending Manager Validation') {
    events.push({
      label: 'Manager Validation',
      done: false,
      timestamp: null,
      actor: null,
      notes: null,
      tone: null,
    });
  }

  // HR / final
  if (status === 'Approved') {
    events.push({
      label: 'Approved',
      done: true,
      timestamp: booking.hrDecidedAt || booking.updatedAt,
      actor: booking.hrName,
      notes: booking.hrNotes,
      tone: 'accent',
    });
  } else if (status === 'Declined') {
    events.push({
      label: 'Declined',
      done: true,
      timestamp: booking.hrDecidedAt || booking.updatedAt,
      actor: booking.hrName,
      notes: booking.hrNotes,
      tone: 'clay',
    });
  } else if (status === 'Rescheduled') {
    events.push({
      label: 'Rescheduled',
      done: true,
      timestamp: booking.hrDecidedAt || booking.updatedAt,
      actor: booking.hrName,
      notes: booking.hrNotes,
      tone: 'clay',
    });
  } else if (['Pending HR Decision', 'Manager Validated'].includes(status)) {
    events.push({
      label: 'HR Review',
      done: false,
      timestamp: null,
      actor: null,
      notes: null,
      tone: null,
    });
  } else if (status === 'Cancelled') {
    events.push({
      label: 'Cancelled',
      done: true,
      timestamp: booking.updatedAt,
      actor: null,
      notes: booking.hrNotes || booking.managerNotes,
      tone: 'muted',
    });
  }

  // Ensure at least submitted + one pending if nothing else
  if (events.length === 1 && status === 'Pending Manager Validation') {
    // already has pending manager
  }

  const items = events
    .map((ev, i) => {
      const isLast = i === events.length - 1;
      const dot =
        ev.done
          ? ev.tone === 'clay'
            ? 'bg-clay border-clay'
            : ev.tone === 'muted'
              ? 'bg-muted border-muted'
              : 'bg-accent border-accent'
          : 'bg-paper border-rule';
      const line = isLast ? '' : `<div class="absolute left-[7px] top-5 bottom-0 w-px ${ev.done ? 'bg-accent/40' : 'bg-rule'}"></div>`;

      return `
        <div class="relative pl-8 pb-8 last:pb-0">
          ${line}
          <div class="absolute left-0 top-1 w-[15px] h-[15px] rounded-full border-2 ${dot}"></div>
          <div class="${ev.done ? '' : 'opacity-50'}">
            <p class="text-sm font-medium text-ink tracking-tight">${escapeHtml(ev.label)}</p>
            ${ev.timestamp ? `<p class="mt-0.5 text-xs text-muted">${escapeHtml(formatDateTime(ev.timestamp))}</p>` : ''}
            ${ev.actor ? `<p class="mt-0.5 text-xs text-muted">${escapeHtml(ev.actor)}</p>` : ''}
            ${ev.notes ? `<p class="mt-2 text-sm text-ink/80 leading-relaxed border-l-2 border-rule pl-3">${escapeHtml(ev.notes)}</p>` : ''}
          </div>
        </div>
      `;
    })
    .join('');

  return `<div class="timeline">${items}</div>`;
}
