/**
 * Sticky header: page title, hamburger, notification bell, user menu.
 */

import { session, logout } from '../js/auth.js';
import { openDrawer } from './sidebar.js';
import { escapeHtml } from '../js/utils.js';

export function renderHeader(container) {
  const title = document.body.dataset.pageTitle || 'Dashboard';
  const subtitle = document.body.dataset.pageSubtitle || '';
  const user = session();

  container.innerHTML = `
    <header class="sticky top-0 z-40 border-b border-rule/80 bg-paper/85 backdrop-blur-md">
      <div class="h-14 flex items-center gap-3 px-4 sm:px-6 lg:px-8">
        <button type="button" id="header-menu" class="lg:hidden p-2 -ml-2 text-muted hover:text-ink rounded-sm hover:bg-rule/40 transition-colors duration-150" aria-label="Open menu">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
        </button>

        <div class="flex-1 min-w-0">
          <h1 class="font-display text-[17px] sm:text-lg font-medium tracking-[-0.025em] text-ink truncate leading-tight">${escapeHtml(title)}</h1>
          ${subtitle ? `<p class="text-[11px] text-muted tracking-wide mt-0.5 truncate hidden sm:block">${escapeHtml(subtitle)}</p>` : ''}
        </div>

        <div class="flex items-center gap-1 sm:gap-2">
          <a href="/pages/notifications.html"
             class="relative p-2 text-muted hover:text-ink rounded-sm hover:bg-rule/40 transition-colors duration-150"
             aria-label="Notifications">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>
          </a>

          <div class="w-px h-5 bg-rule mx-1 hidden sm:block"></div>

          <div class="relative" id="user-menu-wrap">
            <button type="button" id="user-menu-btn"
              class="flex items-center gap-2.5 pl-1.5 pr-2 py-1.5 rounded-sm hover:bg-rule/40 transition-colors duration-150 text-sm text-ink">
              <span class="w-7 h-7 rounded-sm bg-ink text-paper flex items-center justify-center text-[10px] font-medium tracking-wide">${initials(user?.name)}</span>
              <span class="hidden md:inline text-[13px] font-medium max-w-[120px] truncate">${escapeHtml(user?.name || '—')}</span>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-muted hidden sm:block"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div id="user-menu" class="hidden absolute right-0 top-full mt-1.5 w-52 bg-paper border border-rule shadow-card rounded-sm py-1 z-50">
              <div class="px-3.5 py-3 border-b border-rule">
                <p class="text-[13px] font-medium text-ink truncate">${escapeHtml(user?.name || '—')}</p>
                <p class="text-[11px] text-muted truncate mt-0.5">${escapeHtml(user?.email || '')}</p>
              </div>
              <a href="/pages/settings.html" class="block px-3.5 py-2.5 text-[13px] text-ink hover:bg-rule/30 transition-colors duration-150">Settings</a>
              <button type="button" id="header-logout" class="w-full text-left px-3.5 py-2.5 text-[13px] text-ink hover:bg-rule/30 transition-colors duration-150">Sign out</button>
            </div>
          </div>
        </div>
      </div>
    </header>
  `;

  container.querySelector('#header-menu')?.addEventListener('click', openDrawer);
  container.querySelector('#header-logout')?.addEventListener('click', () => logout());

  const btn = container.querySelector('#user-menu-btn');
  const menu = container.querySelector('#user-menu');
  btn?.addEventListener('click', (e) => {
    e.stopPropagation();
    menu?.classList.toggle('hidden');
  });
  document.addEventListener('click', () => menu?.classList.add('hidden'));
}

function initials(name) {
  if (!name) return '—';
  return name
    .split(/\s+/)
    .map((p) => p[0])
    .slice(0, 2)
    .join('')
    .toUpperCase();
}
