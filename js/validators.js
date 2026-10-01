/**
 * Form and conflict validators.
 *
 * Client-side conflict detection is UX only.
 * The backend must re-validate before persisting.
 */

/**
 * Overlap rule for [s1, e1) and [s2, e2):
 * s1 < e2 && e1 > s2
 */
export function intervalsOverlap(s1, e1, s2, e2) {
  return s1 < e2 && e1 > s2;
}

/**
 * Check whether the proposed venue/date/start/end conflicts with
 * any loaded approved or rescheduled booking for that venue and date.
 * @param {object} proposed - { venueId, date, startTime, endTime }
 * @param {Array} existing - bookings with status Approved | Rescheduled
 * @returns {boolean} true if conflict exists
 */
export function hasVenueConflict(proposed, existing = []) {
  if (!proposed?.venueId || !proposed?.date || !proposed?.startTime || !proposed?.endTime) {
    return false;
  }

  const day = proposed.date;
  const s1 = toMinutes(proposed.startTime);
  const e1 = toMinutes(proposed.endTime);
  if (s1 == null || e1 == null || e1 <= s1) return false;

  return existing.some((b) => {
    if (b.venueId !== proposed.venueId && b.venue?.id !== proposed.venueId) return false;
    if (b.date !== day && normalizeDate(b.date) !== day) return false;
    const status = b.status;
    if (status !== 'Approved' && status !== 'Rescheduled') return false;
    const s2 = toMinutes(b.startTime);
    const e2 = toMinutes(b.endTime);
    if (s2 == null || e2 == null) return false;
    return intervalsOverlap(s1, e1, s2, e2);
  });
}

function toMinutes(t) {
  if (!t) return null;
  const m = String(t).match(/^(\d{1,2}):(\d{2})/);
  if (!m) return null;
  return Number(m[1]) * 60 + Number(m[2]);
}

function normalizeDate(d) {
  if (!d) return '';
  if (typeof d === 'string' && /^\d{4}-\d{2}-\d{2}/.test(d)) return d.slice(0, 10);
  const dt = new Date(d);
  if (Number.isNaN(dt.getTime())) return '';
  return dt.toISOString().slice(0, 10);
}

export function required(value, label = 'Field') {
  if (value == null || String(value).trim() === '') {
    return `${label} is required.`;
  }
  return null;
}

export function validateBookingForm(fields) {
  const errors = {};
  const checks = [
    ['venueId', 'Venue'],
    ['date', 'Date'],
    ['startTime', 'Start time'],
    ['endTime', 'End time'],
    ['purpose', 'Purpose'],
    ['eventType', 'Event type'],
    ['attendees', 'Number of attendees'],
    ['departmentManagerId', 'Department manager'],
  ];
  for (const [key, label] of checks) {
    const err = required(fields[key], label);
    if (err) errors[key] = err;
  }
  if (fields.startTime && fields.endTime) {
    const s = toMinutes(fields.startTime);
    const e = toMinutes(fields.endTime);
    if (s != null && e != null && e <= s) {
      errors.endTime = 'End time must be after start time.';
    }
  }
  if (fields.attendees != null && fields.attendees !== '') {
    const n = Number(fields.attendees);
    if (!Number.isFinite(n) || n < 1) {
      errors.attendees = 'Attendees must be at least 1.';
    }
  }
  return errors;
}
