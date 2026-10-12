/**
 * Booking calendar: month grid -> day timeline -> start slot -> duration chips.
 *
 * Rules come from the server (GET /venues/:id/availability), which reads the
 * admin Settings: lead days, buffer, time step, min/max duration, hours.
 * This UI is a convenience only; the backend re-validates everything.
 */

import { getVenueAvailability } from '../js/api.js';
import { escapeHtml, formatTime } from '../js/utils.js';
import { bookingPhase } from './status-badge.js';

const ROW_H = 36; // px per step row in the day timeline
const pad = (n) => String(n).padStart(2, '0');

export const toMin = (t) => {
  const [h, m] = String(t).split(':');
  return Number(h) * 60 + Number(m);
};
export const toHHMM = (m) => `${pad(Math.floor(m / 60))}:${pad(m % 60)}`;
const fmtMin = (m) => formatTime(toHHMM(m));
const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const parseYmd = (s) => new Date(Number(s.slice(0, 4)), Number(s.slice(5, 7)) - 1, Number(s.slice(8, 10)));

/** 30 -> "30 min", 60 -> "1 hr", 90 -> "1 hr 30 min", 120 -> "2 hrs" */
export function fmtDuration(min) {
  const h = Math.floor(min / 60);
  const r = min % 60;
  if (!h) return `${r} min`;
  return r ? `${h} hr ${r} min` : `${h} hr${h > 1 ? 's' : ''}`;
}

/**
 * Per-row state for one day.
 * state: 'free' (can start here) | 'booked' | 'buffer' (blocked by the gap rule or too short) | 'past'
 * maxEnd: latest minute a booking starting on this row may end.
 */
export function computeDaySlots(cfg, dayBlocks, { isToday = false, nowMin = 0 } = {}) {
  const o = toMin(cfg.opens);
  const c = toMin(cfg.closes);
  const step = cfg.stepMinutes;
  const buf = cfg.bufferMinutes;
  const minD = Math.ceil(cfg.minDuration / step) * step;
  const blocks = dayBlocks.map((b) => ({ s: toMin(b.start), e: toMin(b.end) })).sort((a, b) => a.s - b.s);

  const rows = [];
  for (let t = o; t < c; t += step) {
    if (blocks.some((b) => t < b.e && t + step > b.s)) {
      rows.push({ t, state: 'booked', maxEnd: t });
      continue;
    }
    if (isToday && t <= nowMin) {
      rows.push({ t, state: 'past', maxEnd: t });
      continue;
    }
    const prevEnd = Math.max(-Infinity, ...blocks.filter((b) => b.e <= t).map((b) => b.e));
    const next = blocks.find((b) => b.s > t);
    const maxEnd = Math.min(c, next ? next.s - buf : c);
    const startable = t >= prevEnd + buf && maxEnd - t >= minD;
    rows.push({ t, state: startable ? 'free' : 'buffer', maxEnd });
  }
  return rows;
}

/** Allowed durations (minutes) for a start time. */
export function durationOptions(cfg, startMin, maxEnd) {
  const step = cfg.stepMinutes;
  const first = Math.ceil(cfg.minDuration / step) * step;
  const out = [];
  for (let d = first; d <= cfg.maxDuration && startMin + d <= maxEnd; d += step) out.push(d);
  return out;
}

export function mountBookingCalendar(root, { onChange, onError } = {}) {
  const state = {
    venueId: null,
    cfg: null,
    blocks: {}, // 'YYYY-MM-DD' -> [{start,end,mine}]
    loaded: new Set(), // month keys
    month: null, // Date (first of month)
    date: null,
    start: null, // minutes
    duration: null, // minutes
    loading: false,
    autoScroll: false,
    error: '',
  };

  const monthKey = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}`;

  async function loadMonth(m, { force = false } = {}) {
    if (!state.venueId) return;
    const key = monthKey(m);
    if (!force && state.loaded.has(key)) return;
    const from = ymd(new Date(m.getFullYear(), m.getMonth(), 1));
    const to = ymd(new Date(m.getFullYear(), m.getMonth() + 1, 0));
    state.loading = true;
    state.error = '';
    render();
    try {
      const data = await getVenueAvailability(state.venueId, from, to);
      state.cfg = data.config;
      // replace that month's blocks
      for (const k of Object.keys(state.blocks)) if (k.startsWith(key)) delete state.blocks[k];
      for (const b of data.blocks) (state.blocks[b.date] ||= []).push(b);
      state.loaded.add(key);
    } catch (err) {
      state.error = err.message || 'Could not load availability.';
      onError?.(err);
    } finally {
      state.loading = false;
    }
  }

  function emit() {
    const complete = state.date && state.start != null && state.duration;
    onChange?.({
      date: state.date || '',
      startTime: complete ? toHHMM(state.start) : '',
      endTime: complete ? toHHMM(state.start + state.duration) : '',
    });
  }

  function clearSelection({ keepDate = false } = {}) {
    if (!keepDate) state.date = null;
    state.start = null;
    state.duration = null;
    emit();
  }

  async function setVenue(venueId) {
    state.venueId = venueId ? Number(venueId) : null;
    state.cfg = null;
    state.blocks = {};
    state.loaded = new Set();
    state.month = null;
    clearSelection();
    if (!state.venueId) return render();
    await loadMonth(new Date());
    if (state.cfg) {
      // Open on the month of the first bookable date.
      const first = parseYmd(state.cfg.minDate);
      state.month = new Date(first.getFullYear(), first.getMonth(), 1);
      await loadMonth(state.month);
    }
    render();
  }

  async function refresh() {
    if (!state.venueId || !state.month) return;
    const keep = { date: state.date };
    state.loaded = new Set();
    await loadMonth(state.month, { force: true });
    // the chosen slot may have been taken meanwhile
    clearSelection({ keepDate: !!keep.date });
    render();
  }

  // ───────────── rendering ─────────────
  function render() {
    if (!state.venueId) {
      root.innerHTML = `<div class="rounded border border-dashed border-rule px-4 py-10 text-center text-sm text-muted">Select a venue to see its availability.</div>`;
      return;
    }
    if (!state.cfg) {
      root.innerHTML = `<div class="rounded border border-rule px-4 py-10 text-center text-sm ${state.error ? 'text-clay' : 'text-muted'}">${escapeHtml(state.error || 'Loading availability…')}</div>`;
      return;
    }
    const prevScroll = root.querySelector('[data-scroll]')?.scrollTop ?? null;
    root.innerHTML = `
      <div class="grid gap-8 lg:grid-cols-[340px_1fr]">
        <div>${stepHead(1, 'Choose a date')}${monthHtml()}</div>
        <div>${stepHead(2, 'Pick a start time')}${dayHtml()}</div>
      </div>
      ${legendHtml()}
      ${state.error ? `<p class="mt-2 text-xs text-clay">${escapeHtml(state.error)}</p>` : ''}`;
    bind();
    const sc = root.querySelector('[data-scroll]');
    if (sc) {
      if (state.autoScroll) {
        const rows = computeDaySlots(state.cfg, state.blocks[state.date] || [], nowInfo(state.date));
        const i = rows.findIndex((r) => r.state === 'free');
        sc.scrollTop = Math.max(0, (i - 1) * ROW_H);
        state.autoScroll = false;
      } else if (prevScroll != null) {
        sc.scrollTop = prevScroll;
      }
    }
  }

  function stepHead(n, label, done = false) {
    return `<p class="mb-3 flex items-center gap-2 text-sm font-medium"><span class="flex h-5 w-5 items-center justify-center rounded-full bg-ink text-[11px] text-paper">${n}</span>${escapeHtml(label)}</p>`;
  }

  function dayStatus(dateStr) {
    const cfg = state.cfg;
    const disabled = dateStr < cfg.minDate || dateStr > cfg.maxDate;
    const blocks = state.blocks[dateStr] || [];
    let full = false;
    if (!disabled) {
      const rows = computeDaySlots(cfg, blocks, nowInfo(dateStr));
      full = !rows.some((r) => r.state === 'free');
    }
    return { disabled, blocks, full };
  }

  function nowInfo(dateStr) {
    const n = new Date();
    return { isToday: dateStr === ymd(n), nowMin: n.getHours() * 60 + n.getMinutes() };
  }

  function monthHtml() {
    const cfg = state.cfg;
    const m = state.month;
    const first = new Date(m.getFullYear(), m.getMonth(), 1);
    const daysInMonth = new Date(m.getFullYear(), m.getMonth() + 1, 0).getDate();
    const lead = (first.getDay() + 6) % 7; // Monday first
    const minM = parseYmd(cfg.minDate);
    const maxM = parseYmd(cfg.maxDate);
    const canPrev = first > new Date(minM.getFullYear(), minM.getMonth(), 1);
    const canNext = first < new Date(maxM.getFullYear(), maxM.getMonth(), 1);

    let cells = '';
    for (let i = 0; i < lead; i++) cells += '<div></div>';
    for (let d = 1; d <= daysInMonth; d++) {
      const ds = ymd(new Date(m.getFullYear(), m.getMonth(), d));
      const st = dayStatus(ds);
      const sel = ds === state.date;
      const note = st.disabled ? '' : st.full ? 'Full' : st.blocks.length ? `${st.blocks.length} booked` : '';
      cells += `
        <button type="button" data-day="${ds}" ${st.disabled ? 'disabled' : ''}
          class="h-12 rounded-sm border text-sm flex flex-col items-center justify-center leading-tight transition-colors duration-150
            ${sel ? 'bg-ink text-paper border-ink' : st.disabled ? 'border-transparent text-muted/40 cursor-not-allowed' : st.full ? 'border-rule bg-clay/10 text-ink hover:border-ink/40' : 'border-rule hover:border-ink/40 text-ink'}"
          ${st.disabled && ds < cfg.minDate ? `title="Bookings need at least ${cfg.leadDays} day(s) notice"` : ''}>
          <span>${d}</span>
          <span class="text-[9px] ${sel ? 'text-paper/80' : st.full ? 'text-clay' : 'text-muted'}">${note}</span>
        </button>`;
    }
    return `
      <div class="flex items-center justify-between mb-3">
        <button type="button" data-nav="-1" ${canPrev ? '' : 'disabled'} class="px-2 py-1 text-sm rounded hover:bg-rule/40 disabled:opacity-30" aria-label="Previous month">‹</button>
        <p class="font-display text-lg tracking-tight">${m.toLocaleDateString('en-US', { month: 'long', year: 'numeric' })}</p>
        <button type="button" data-nav="1" ${canNext ? '' : 'disabled'} class="px-2 py-1 text-sm rounded hover:bg-rule/40 disabled:opacity-30" aria-label="Next month">›</button>
      </div>
      <div class="grid grid-cols-7 gap-1 mb-1 text-center text-[10px] uppercase tracking-[0.08em] text-muted">
        ${['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((x) => `<div>${x}</div>`).join('')}
      </div>
      <div class="grid grid-cols-7 gap-1">${cells}</div>
      <p class="mt-3 text-xs text-muted">Earliest bookable date: <strong class="text-ink">${escapeHtml(parseYmd(cfg.minDate).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }))}</strong>
        (${cfg.leadDays} day${cfg.leadDays === 1 ? '' : 's'} notice).</p>`;
  }

  function dayHtml() {
    const cfg = state.cfg;
    if (!state.date) {
      return `<div class="h-full min-h-[200px] rounded border border-dashed border-rule flex items-center justify-center px-4 text-center text-sm text-muted">Pick a date to see booked times and choose a start time.</div>`;
    }
    const blocks = (state.blocks[state.date] || []).slice().sort((a, b) => toMin(a.start) - toMin(b.start));
    const rows = computeDaySlots(cfg, blocks, nowInfo(state.date));
    const o = toMin(cfg.opens);
    const c = toMin(cfg.closes);
    const step = cfg.stepMinutes;
    const px = (m) => ((m - o) / step) * ROW_H;
    const dateLabel = parseYmd(state.date).toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });

    const lines = rows
      .map((r) => {
        const free = r.state === 'free';
        const on = state.start === r.t;
        return `
        <div class="flex border-b border-rule/70" style="height:${ROW_H}px">
          <div class="w-20 shrink-0 pr-2 pt-1 text-right text-[11px] text-muted">${escapeHtml(fmtMin(r.t))}</div>
          <button type="button" data-start="${r.t}" ${free ? '' : 'disabled'}
            class="group flex-1 text-left px-2 text-xs transition-colors duration-100 ${free ? 'hover:bg-accent-soft cursor-pointer' : 'cursor-not-allowed'} ${on ? 'bg-accent-soft' : ''}"
            aria-label="${free ? `Start at ${escapeHtml(fmtMin(r.t))}` : 'Unavailable'}">
            ${free && !on ? '<span class="text-muted opacity-0 group-hover:opacity-100">Start here</span>' : ''}
          </button>
        </div>`;
      })
      .join('');

    const overlays = [];
    for (const b of blocks) {
      const s = toMin(b.start);
      const e = toMin(b.end);
      const top = px(Math.max(s, o));
      const h = px(Math.min(e, c)) - top;
      // buffer zones (hatched)
      const hatch = 'background-image:repeating-linear-gradient(45deg,rgba(26,24,21,.07) 0 4px,transparent 4px 8px)';
      const bBefore = px(Math.max(o, s - cfg.bufferMinutes));
      if (cfg.bufferMinutes && s > o) overlays.push(`<div class="absolute left-20 right-0 border-y border-dashed border-rule pointer-events-none" style="top:${bBefore}px;height:${top - bBefore}px;${hatch}"></div>`);
      const bAfterEnd = Math.min(c, e + cfg.bufferMinutes);
      if (cfg.bufferMinutes && e < c) overlays.push(`<div class="absolute left-20 right-0 border-y border-dashed border-rule pointer-events-none" style="top:${top + h}px;height:${px(bAfterEnd) - (top + h)}px;${hatch}"></div>`);
      const live = bookingPhase({ status: 'Approved', date: state.date, startTime: b.start, endTime: b.end }) === 'live';
      overlays.push(`
        <div class="absolute left-20 right-0 rounded-sm px-2 flex items-center text-xs overflow-hidden pointer-events-none ${live ? 'bg-[#0d6efd] text-white' : b.mine ? 'bg-accent text-paper' : 'bg-clay text-paper'}"
             style="top:${top}px;height:${Math.max(h, 18)}px" title="${live ? 'Meeting in progress' : b.mine ? 'Your booking' : 'Booked'}">
          <span class="truncate">${live ? 'In progress' : b.mine ? 'Your booking' : 'Booked'} · ${escapeHtml(fmtMin(s))} – ${escapeHtml(fmtMin(e))} · ${escapeHtml(fmtDuration(e - s))}</span>
        </div>`);
    }
    if (state.start != null && state.duration) {
      overlays.push(`
        <div class="absolute left-20 right-0 rounded-sm border-2 border-accent bg-accent/15 px-2 flex items-center text-xs font-medium text-accent pointer-events-none"
             style="top:${px(state.start)}px;height:${px(state.start + state.duration) - px(state.start)}px">
          <span class="truncate">Your request · ${escapeHtml(fmtMin(state.start))} – ${escapeHtml(fmtMin(state.start + state.duration))}</span>
        </div>`);
    }

    return `
      <p class="mb-2 text-xs text-muted">${escapeHtml(dateLabel)}</p>
      <div data-scroll class="max-h-[360px] overflow-y-auto rounded border border-rule bg-paper">
        <div class="relative" style="height:${rows.length * ROW_H}px">${lines}${overlays.join('')}</div>
      </div>
      <div class="mt-5">${stepHead(3, 'Set the duration')}${durationHtml(rows)}</div>`;
  }

  function limitReason(rows) {
    const cfg = state.cfg;
    const row = rows.find((r) => r.t === state.start);
    const nextEnd = state.start + state.duration + cfg.stepMinutes;
    if (state.duration + cfg.stepMinutes > cfg.maxDuration) return `Longest allowed booking is ${fmtDuration(cfg.maxDuration)}.`;
    if (nextEnd > row.maxEnd) {
      return row.maxEnd >= toMin(cfg.closes)
        ? `The venue closes at ${fmtMin(toMin(cfg.closes))}.`
        : `Another booking follows, so this is the longest slot available.`;
    }
    return '';
  }

  function durationHtml(rows) {
    const cfg = state.cfg;
    if (state.start == null) {
      const any = rows.some((r) => r.state === 'free');
      return `<p class="mt-4 text-sm text-muted">${any ? 'Click a free time above to set your start.' : 'No free start times on this day.'}</p>`;
    }
    const row = rows.find((r) => r.t === state.start);
    const opts = durationOptions(cfg, state.start, row.maxEnd);
    const idx = opts.indexOf(state.duration);
    const canMinus = idx > 0;
    const canPlus = idx >= 0 && idx < opts.length - 1;
    const end = state.start + state.duration;
    const btn = (dir, on, label) => `<button type="button" data-dur-step="${dir}" ${on ? '' : 'disabled'} aria-label="${dir < 0 ? 'Shorter' : 'Longer'}"
        class="h-10 w-10 rounded-full border border-rule text-lg leading-none flex items-center justify-center transition-colors duration-150 ${on ? 'hover:border-ink hover:bg-ink hover:text-paper' : 'opacity-30 cursor-not-allowed'}">${label}</button>`;
    const reason = canPlus ? '' : limitReason(rows);
    return `
      <div class="mt-4 rounded border border-rule bg-paper p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div>
            <p class="text-[11px] font-medium uppercase tracking-[0.08em] text-muted">Starts</p>
            <p class="font-display text-xl tracking-tight">${escapeHtml(fmtMin(state.start))}</p>
          </div>
          <div class="text-center">
            <p class="mb-1 text-[11px] font-medium uppercase tracking-[0.08em] text-muted">Duration</p>
            <div class="flex items-center gap-3">
              ${btn(-1, canMinus, '−')}
              <span class="min-w-[104px] text-center font-display text-xl tracking-tight" aria-live="polite">${escapeHtml(fmtDuration(state.duration))}</span>
              ${btn(1, canPlus, '+')}
            </div>
          </div>
          <div class="text-right">
            <p class="text-[11px] font-medium uppercase tracking-[0.08em] text-muted">Ends</p>
            <p class="font-display text-xl tracking-tight">${escapeHtml(fmtMin(end))}</p>
          </div>
        </div>
        ${reason ? `<p class="mt-3 text-xs text-muted">${escapeHtml(reason)}</p>` : `<p class="mt-3 text-xs text-muted">Use − / + to change the length in ${cfg.stepMinutes === 60 ? '1-hour' : '30-minute'} steps.</p>`}
      </div>`;
  }

  function legendHtml() {
    const cfg = state.cfg;
    const sw = (cls, label, extra = '') => `<span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm ${cls}" style="${extra}"></span>${label}</span>`;
    return `
      <div class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
        ${sw('bg-clay', 'Booked')}
        ${sw('bg-accent', 'Your booking')}
        ${cfg.bufferMinutes ? sw('border border-dashed border-rule', `${cfg.bufferMinutes}-min buffer`, 'background-image:repeating-linear-gradient(45deg,rgba(26,24,21,.15) 0 3px,transparent 3px 6px)') : ''}
        ${sw('border-2 border-accent bg-accent/15', 'Your request')}
        <span>Open ${escapeHtml(formatTime(cfg.opens))} – ${escapeHtml(formatTime(cfg.closes))} · ${cfg.stepMinutes === 60 ? 'whole hours only' : 'hours or half hours'}</span>
      </div>`;
  }

  function bind() {
    root.querySelectorAll('[data-nav]').forEach((b) =>
      b.addEventListener('click', async () => {
        const d = Number(b.dataset.nav);
        state.month = new Date(state.month.getFullYear(), state.month.getMonth() + d, 1);
        await loadMonth(state.month);
        render();
      })
    );
    root.querySelectorAll('[data-day]').forEach((b) =>
      b.addEventListener('click', () => {
        state.date = b.dataset.day;
        state.autoScroll = true;
        state.start = null;
        state.duration = null;
        emit();
        render();
      })
    );
    root.querySelectorAll('[data-start]').forEach((b) =>
      b.addEventListener('click', () => {
        state.start = Number(b.dataset.start);
        const rows = computeDaySlots(state.cfg, state.blocks[state.date] || [], nowInfo(state.date));
        const opts = durationOptions(state.cfg, state.start, rows.find((r) => r.t === state.start).maxEnd);
        state.duration = opts.includes(60) ? 60 : opts[0] || null; // default 1 hr
        emit();
        render();
      })
    );
    root.querySelectorAll('[data-dur-step]').forEach((b) =>
      b.addEventListener('click', () => {
        const rows = computeDaySlots(state.cfg, state.blocks[state.date] || [], nowInfo(state.date));
        const opts = durationOptions(state.cfg, state.start, rows.find((r) => r.t === state.start).maxEnd);
        const i = opts.indexOf(state.duration) + Number(b.dataset.durStep);
        if (i >= 0 && i < opts.length) {
          state.duration = opts[i];
          emit();
          render();
        }
      })
    );
  }

  render();
  return { setVenue, refresh };
}
