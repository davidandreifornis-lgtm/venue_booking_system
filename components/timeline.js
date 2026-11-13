/**
 * Booking request vertical timeline + full status history audit trail.
 */

import { formatDateTime, escapeHtml } from '../js/utils.js';

/**
 * Build timeline from a booking record.
 * Prefer booking.statusHistory (server audit log) when present.
 */
export function timeline(booking) {
  if (!booking) return '';

  const history = Array.isArray(booking.statusHistory) ? booking.statusHistory : [];
  if (history.length) {
    return renderFromHistory(booking, history);
  }
  return renderFromStatus(booking);
}

function renderFromHistory(booking, history) {
  const items = history.map((h, i) => {
    const label = h.toStatus || 'Update';
    const actor = h.isSystem
      ? 'System'
      : (h.changedByName || h.changedByEmail || 'User');
    const notes = h.reason || null;
    const tone = toneForStatus(h.toStatus);
    return eventHtml({
      label,
      done: true,
      timestamp: h.changedAt,
      actor,
      notes,
      tone,
      fromStatus: h.fromStatus,
      isLast: i === history.length - 1,
    });
  });

  return `
    <div class="timeline" role="list" aria-label="Booking history">
      ${items.join('')}
    </div>
    <p class="text-xs text-muted mt-3">Every status change is recorded with actor and reason for audit.</p>
  `;
}

function renderFromStatus(booking) {
  const status = booking.status || '';
  const events = [];

  events.push({
    label: 'Request Submitted',
    done: true,
    timestamp: booking.createdAt || booking.createdDate,
    actor: booking.requesterName || booking.requester,
    notes: null,
    tone: 'accent',
  });

  if (['Manager Validated', 'Pending HR Decision', 'Approved', 'Declined', 'Rescheduled', 'Expired', 'Cancelled'].includes(status)
      && status !== 'Pending Manager Validation' && status !== 'Rejected by Manager') {
    if (booking.managerValidatedAt || booking.validatedAt) {
      events.push({
        label: 'Manager Validated',
        done: true,
        timestamp: booking.managerValidatedAt || booking.validatedAt,
        actor: booking.managerName || booking.departmentManager,
        notes: booking.managerNotes,
        tone: 'accent',
      });
    }
  } else if (status === 'Pending Manager Validation') {
    events.push({
      label: 'Manager Validation',
      done: false,
      timestamp: null,
      actor: null,
      notes: null,
      tone: null,
    });
  } else if (status === 'Rejected by Manager') {
    events.push({
      label: 'Rejected by Manager',
      done: true,
      timestamp: booking.updatedAt,
      actor: booking.managerName,
      notes: booking.managerNotes || booking.declineReason,
      tone: 'clay',
    });
  }

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
      notes: booking.hrNotes || booking.declineReason,
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
  } else if (status === 'Expired') {
    events.push({
      label: 'Expired',
      done: true,
      timestamp: booking.updatedAt,
      actor: 'System',
      notes: 'Event date passed while still undecided',
      tone: 'clay',
    });
  } else if (status === 'Cancelled') {
    events.push({
      label: 'Cancelled',
      done: true,
      timestamp: booking.updatedAt,
      actor: null,
      notes: booking.cancelReason,
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
  } else if (status === 'Revision Requested') {
    events.push({
      label: 'Revision Requested',
      done: true,
      timestamp: booking.updatedAt,
      actor: null,
      notes: booking.managerNotes || booking.hrNotes,
      tone: 'clay',
    });
  }

  return `
    <div class="timeline" role="list" aria-label="Booking progress">
      ${events.map((e, i) => eventHtml({ ...e, isLast: i === events.length - 1 })).join('')}
    </div>
  `;
}

function toneForStatus(status) {
  if (!status) return 'accent';
  if (['Approved', 'Completed', 'Manager Validated', 'Pending HR Decision'].includes(status)) return 'accent';
  if (['Declined', 'Rejected by Manager', 'Cancelled', 'Expired', 'No-Show'].includes(status)) return 'clay';
  return 'accent';
}

function eventHtml({ label, done, timestamp, actor, notes, tone, fromStatus, isLast }) {
  const dotCls = done
    ? (tone === 'clay' ? 'bg-clay border-clay' : 'bg-accent border-accent')
    : 'bg-paper border-rule';
  const line = isLast ? '' : '<span class="timeline-line" aria-hidden="true"></span>';
  const when = timestamp ? formatDateTime(timestamp) : (done ? '—' : 'Waiting');
  const who = actor ? escapeHtml(actor) : (done ? '—' : '—');
  const from = fromStatus
    ? `<span class="text-muted">from ${escapeHtml(fromStatus)}</span> → `
    : '';
  return `
    <div class="timeline-item" role="listitem">
      <div class="timeline-rail">
        <span class="timeline-dot border-2 ${dotCls}"></span>
        ${line}
      </div>
      <div class="timeline-body ${done ? '' : 'opacity-60'}">
        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
          <span class="font-medium text-sm">${from}<span class="${done ? 'text-ink' : 'text-muted'}">${escapeHtml(label)}</span></span>
          <span class="text-[11px] text-muted">${escapeHtml(when)}</span>
        </div>
        <div class="text-xs text-muted mt-0.5">${who !== '—' ? `By ${who}` : ''}</div>
        ${notes ? `<p class="mt-1 text-sm text-ink/90 border-l-2 border-rule pl-2">${escapeHtml(notes)}</p>` : ''}
      </div>
    </div>
  `;
}
