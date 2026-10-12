/**
 * Calendar rendering helpers.
 * Week grid on desktop, day list on mobile.
 * No sample events.
 */

import { formatDate, formatTime, escapeHtml } from './utils.js';
import { emptyState } from '../components/empty-state.js';
import { bookingPhase } from '../components/status-badge.js';

const HOURS = Array.from({ length: 9 }, (_, i) => i + 8); // 8:00 AM – 5:00 PM

export function renderWeekGrid(container, { events = [], weekStart, onEventClick } = {}) {
  if (!container) return;

  const days = Array.from({ length: 7 }, (_, i) => {
    const d = new Date(weekStart);
    d.setDate(d.getDate() + i);
    return d;
  });

  if (!events.length) {
    // Still show the grid, with empty overlay
  }

  const dayHeaders = days
    .map(
      (d) => `
      <div class="text-center py-2 border-b border-rule">
        <p class="text-[11px] uppercase tracking-[0.08em] text-muted">${d.toLocaleDateString('en-US', { weekday: 'short' })}</p>
        <p class="font-display text-lg tracking-tight text-ink">${d.getDate()}</p>
      </div>`
    )
    .join('');

  const timeCol = HOURS.map(
    (h) => `
    <div class="h-14 border-b border-rule pr-2 text-right text-[11px] text-muted sticky left-0 bg-paper z-10 flex items-start justify-end pt-1">
      ${formatHour(h)}
    </div>`
  ).join('');

  const dayCols = days
    .map((day, di) => {
      const dayStr = day.toISOString().slice(0, 10);
      const dayEvents = events.filter((e) => (e.date || '').slice(0, 10) === dayStr);
      const cells = HOURS.map((h) => {
        const slotEvents = dayEvents.filter((e) => {
          const startH = parseInt(String(e.startTime).split(':')[0], 10);
          return startH === h;
        });
        const blocks = slotEvents
          .map((ev) => eventBlock(ev, onEventClick))
          .join('');
        return `<div class="h-14 border-b border-r border-rule relative">${blocks}</div>`;
      }).join('');
      return `<div class="min-w-[100px] flex-1">${cells}</div>`;
    })
    .join('');

  container.innerHTML = `
    <div class="overflow-x-auto">
      <div class="min-w-[700px]">
        <div class="grid grid-cols-[56px_1fr] border-b border-rule">
          <div></div>
          <div class="grid grid-cols-7">${dayHeaders}</div>
        </div>
        <div class="grid grid-cols-[56px_1fr] relative">
          <div>${timeCol}</div>
          <div class="grid grid-cols-7 flex-1">${dayCols}</div>
          ${
            events.length === 0
              ? `<div class="absolute inset-0 flex items-center justify-center bg-paper/60 pointer-events-none">
                  <div class="pointer-events-auto">${emptyState({ title: 'No upcoming bookings.', hint: 'Approved and rescheduled events will appear here.' })}</div>
                </div>`
              : ''
          }
        </div>
      </div>
    </div>
  `;
}

export function renderDayList(container, { events = [], date, onEventClick } = {}) {
  if (!container) return;

  if (!events.length) {
    container.innerHTML = emptyState({
      title: 'No upcoming bookings.',
      hint: 'Approved and rescheduled events will appear here.',
    });
    return;
  }

  const items = events
    .map((ev) => {
      const phase = bookingPhase(ev);
      const color =
        phase === 'live'
          ? 'bg-[#0d6efd]/10 border-[#0d6efd]/40'
          : phase === 'done'
            ? 'bg-ink/5 border-rule'
            : ev.status === 'Approved'
              ? 'bg-accent/15 border-accent/40'
              : ev.status === 'Rescheduled'
                ? 'bg-clay/10 border-clay/30'
                : 'border-dashed border-rule bg-transparent';
      return `
        <button type="button" data-id="${escapeHtml(ev.id)}" class="w-full text-left border ${color} rounded-sm p-3 mb-2 hover:shadow-card transition-shadow duration-150">
          <div class="flex items-baseline justify-between gap-2">
            <p class="font-medium text-sm text-ink">${escapeHtml(ev.venueName || ev.venue || 'Venue')}</p>
            <p class="text-xs text-muted shrink-0">${escapeHtml(formatTime(ev.startTime))} – ${escapeHtml(formatTime(ev.endTime))}</p>
          </div>
          <p class="text-xs text-muted mt-1">${phase === 'live' ? '<strong class="text-[#0d6efd]">In progress</strong> · ' : phase === 'done' ? 'Done · ' : ''}${escapeHtml(ev.purpose || '')}</p>
        </button>
      `;
    })
    .join('');

  container.innerHTML = items;

  if (onEventClick) {
    container.querySelectorAll('[data-id]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-id');
        const ev = events.find((e) => String(e.id) === id);
        if (ev) onEventClick(ev);
      });
    });
  }
}

const HOUR_PX = 56; // matches the h-14 hour rows

function eventClasses(ev) {
  const phase = bookingPhase(ev);
  if (phase === 'live') return 'bg-[#0d6efd] text-white ring-2 ring-[#0d6efd]/30';
  if (phase === 'done') return 'bg-ink/25 text-ink';
  if (ev.status === 'Approved') return 'bg-accent text-paper';
  if (ev.status === 'Rescheduled') return 'bg-clay text-paper';
  return 'border border-dashed border-rule bg-paper text-muted';
}

function eventBlock(ev, onClick) {
  const [sh, sm] = String(ev.startTime).split(':').map(Number);
  const [eh, em] = String(ev.endTime).split(':').map(Number);
  const dur = Math.max(15, eh * 60 + em - (sh * 60 + sm));
  const top = (sm / 60) * HOUR_PX;
  const height = Math.max(16, (dur / 60) * HOUR_PX - 2);
  const live = bookingPhase(ev) === 'live';
  const done = bookingPhase(ev) === 'done';
  const label = live ? 'In progress' : done ? 'Done' : '';
  return `
    <button type="button" class="absolute inset-x-0.5 ${eventClasses(ev)} rounded-sm px-1 text-[10px] leading-tight overflow-hidden text-left z-10" style="top:${top + 1}px;height:${height}px" data-id="${escapeHtml(ev.id)}">
      ${label ? `<span class="font-medium">${label} · </span>` : ''}${escapeHtml(ev.purpose || ev.venueName || 'Booking')}
    </button>
  `;
}

function formatHour(h) {
  const ampm = h >= 12 ? 'PM' : 'AM';
  const hr = h % 12 || 12;
  return `${hr} ${ampm}`;
}

export function startOfWeek(date = new Date()) {
  const d = new Date(date);
  const day = d.getDay();
  const diff = d.getDate() - day + (day === 0 ? -6 : 1); // Monday start
  d.setDate(diff);
  d.setHours(0, 0, 0, 0);
  return d;
}
