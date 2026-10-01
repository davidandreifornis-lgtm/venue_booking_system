/**
 * Calendar rendering helpers.
 * Week grid on desktop, day list on mobile.
 * No sample events.
 */

import { formatDate, formatTime, escapeHtml } from './utils.js';
import { emptyState } from '../components/empty-state.js';

const HOURS = Array.from({ length: 14 }, (_, i) => i + 7); // 7:00 – 20:00

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
      const color =
        ev.status === 'Approved'
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
          <p class="text-xs text-muted mt-1">${escapeHtml(ev.purpose || '')}</p>
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

function eventBlock(ev, onClick) {
  const color =
    ev.status === 'Approved'
      ? 'bg-accent text-paper'
      : ev.status === 'Rescheduled'
        ? 'bg-clay text-paper'
        : 'border border-dashed border-rule bg-paper text-muted';
  return `
    <button type="button" class="absolute inset-x-0.5 top-0.5 bottom-0.5 ${color} rounded-sm px-1 text-[10px] leading-tight overflow-hidden text-left z-10" data-id="${escapeHtml(ev.id)}">
      ${escapeHtml(ev.venueName || ev.purpose || 'Booking')}
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
