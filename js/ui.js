/**
 * Global design system — class tokens and small render helpers.
 * Single source of truth for visual language across all pages.
 *
 * Tokens map to Tailwind config colors:
 *   paper, ink, muted, rule, accent, accent-soft, clay
 */

/** @type {const} */
export const ui = {
  /* —— Surfaces —— */
  card: 'rounded border border-rule bg-paper shadow-card',
  cardInteractive: 'rounded border border-rule bg-paper shadow-card hover:border-ink/15 transition-colors duration-150',
  panel: 'rounded border border-rule bg-paper overflow-hidden',

  /* —— Buttons —— */
  btnPrimary:
    'inline-flex items-center justify-center gap-2 rounded px-4 py-2.5 text-sm font-medium text-paper bg-ink hover:bg-ink/90 transition-colors duration-150 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-ink/20',
  btnSecondary:
    'inline-flex items-center justify-center gap-2 rounded border border-rule bg-paper px-4 py-2.5 text-sm font-medium text-ink hover:bg-rule/40 transition-colors duration-150 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-rule',
  btnAccent:
    'inline-flex items-center justify-center gap-2 rounded px-4 py-2.5 text-sm font-medium text-paper bg-accent hover:bg-accent/90 transition-colors duration-150 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-accent/30',
  btnDanger:
    'inline-flex items-center justify-center gap-2 rounded px-4 py-2.5 text-sm font-medium text-paper bg-clay hover:bg-clay/90 transition-colors duration-150 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-clay/30',
  btnGhost:
    'inline-flex items-center justify-center gap-2 rounded px-4 py-2.5 text-sm font-medium text-muted hover:text-ink hover:bg-rule/40 transition-colors duration-150 disabled:opacity-50',
  btnDangerOutline:
    'inline-flex items-center justify-center gap-2 rounded border border-clay/40 px-4 py-2.5 text-sm font-medium text-clay hover:bg-clay/5 transition-colors duration-150 disabled:opacity-50',

  /* —— Forms —— */
  label: 'mb-1.5 block text-[11px] font-medium uppercase tracking-[0.08em] text-muted',
  input:
    'w-full rounded border border-rule bg-paper px-3 py-2.5 text-sm text-ink placeholder:text-muted/40 outline-none transition-colors duration-150 focus:border-ink/40 focus:ring-1 focus:ring-ink/10',
  select:
    'w-full rounded border border-rule bg-paper px-3 py-2.5 text-sm text-ink outline-none transition-colors duration-150 focus:border-ink/40 focus:ring-1 focus:ring-ink/10',
  textarea:
    'w-full rounded border border-rule bg-paper px-3 py-2.5 text-sm text-ink placeholder:text-muted/40 outline-none transition-colors duration-150 focus:border-ink/40 focus:ring-1 focus:ring-ink/10 resize-y min-h-[80px]',
  fieldError: 'mt-1 text-xs text-clay',

  /* —— Type —— */
  pageDesc: 'text-sm text-muted leading-relaxed max-w-2xl',
  sectionTitle: 'font-display text-lg font-medium tracking-[-0.02em] text-ink',
  sectionLabel: 'text-[11px] font-medium uppercase tracking-[0.1em] text-muted',
  body: 'text-sm text-ink',
  bodyMuted: 'text-sm text-muted',
  link: 'text-sm font-medium text-accent hover:text-accent/80 transition-colors duration-150',

  /* —— Layout —— */
  section: 'mb-10',
  sectionHeader: 'flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between mb-5 pb-3 border-b border-rule',
  pageHeader: 'mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between',
  statGrid: 'grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3',
  statGrid4: 'grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4',
  formGrid: 'grid grid-cols-1 gap-5 sm:grid-cols-2',
  filterRow: 'flex flex-wrap items-end gap-3 mb-6',
  chip:
    'px-3 py-1.5 text-xs font-medium uppercase tracking-[0.06em] rounded border transition-colors duration-150',
  chipActive: 'border-ink bg-ink text-paper',
  chipIdle: 'border-rule text-muted hover:border-ink/40 hover:text-ink',
};

/**
 * Page header block: description + optional actions.
 * Title lives in the app header (data-page-title).
 */
export function pageHeader({ description = '', actionsHtml = '' } = {}) {
  if (!description && !actionsHtml) return '';
  return `
    <div class="${ui.pageHeader}">
      ${description ? `<p class="${ui.pageDesc}">${description}</p>` : '<div></div>'}
      ${actionsHtml ? `<div class="flex flex-wrap items-center gap-2 shrink-0">${actionsHtml}</div>` : ''}
    </div>
  `;
}

/**
 * Uniform dashboard stat card.
 */
export function statCard({ label, valueId, value = '0', hint = '', accent = 'rule' } = {}) {
  const bar =
    accent === 'accent' ? 'bg-accent' :
    accent === 'clay' ? 'bg-clay/70' :
    accent === 'ink' ? 'bg-ink/25' :
    'bg-rule';
  return `
    <div class="${ui.card} p-5 relative overflow-hidden min-h-[112px]">
      <div class="absolute left-0 top-0 bottom-0 w-[3px] ${bar}"></div>
      <p class="${ui.sectionLabel}">${label}</p>
      <p id="${valueId}" class="mt-3 font-display text-3xl font-medium tracking-[-0.04em] text-ink leading-none">${value}</p>
      ${hint ? `<p class="mt-2 text-xs text-muted">${hint}</p>` : ''}
    </div>
  `;
}

/**
 * Section header with optional link.
 */
export function sectionHeader({ title, href, linkLabel = 'View all' } = {}) {
  return `
    <div class="${ui.sectionHeader}">
      <h2 class="${ui.sectionTitle}">${title}</h2>
      ${href ? `<a href="${href}" class="${ui.link} shrink-0">${linkLabel}</a>` : ''}
    </div>
  `;
}
