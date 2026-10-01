/**
 * Application header — identical on every authenticated page.
 * Height: 56px (h-14). Sticky. Title from data-page-title.
 */

import { session, logout } from '../js/auth.js';
import { openDrawer } from './sidebar.js';
import { escapeHtml } from '../js/utils.js';

export function renderHeader(container) {
  const title = document.body.dataset.pageTitle || 'Dashboard';
  const user = session();

  container.innerHTML = `
    <header class="sticky top-0 z-40 h-14 border-b border-rule bg-paper/90 backdrop-blur-md">
      <div class="h-full flex items-center gap-3 px-4 sm:px-6 lg:px-8">
        <button type="button" id="header-menu"
          class="lg:hidden flex items-center justify-center w-9 h-9 -ml-1 rounded text-muted hover:text-ink hover:bg-rule/50 transition-colors duration-150"
          aria-label="Open menu">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
        </button>

        <h1 class="flex-1 min-w-0 font-display text-lg font-medium tracking-[-0.02em] text-ink truncate">${escapeHtml(title)}</h1>

        <div class="flex items-center gap-1">
          <a href="/pages/notifications.html"
             class="flex items-center justify-center w-9 h-9 rounded text-muted hover:text-ink hover:bg-rule/50 transition-colors duration-150"
             aria-label="Notifications">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>
          </a>

          <div class="w-px h-5 bg-rule mx-1 hidden sm:block" aria-hidden="true"></div>

          <div class="relative" id="user-menu-wrap">
            <button type="button" id="user-menu-btn"
              class="flex items-center gap-2 h-9 pl-1 pr-2 rounded hover:bg-rule/50 transition-colors duration-150">
              <span class="w-7 h-7 rounded bg-ink text-paper flex items-center justify-center text-[10px] font-medium tracking-wide">${initials(user?.name)}</span>
              <span class="hidden md:inline text-sm font-medium text-ink max-w-[120px] truncate">${escapeHtml(user?.name || '—')}</span>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-muted hidden sm:block"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div id="user-menu" class="hidden absolute right-0 top-full mt-1.5 w-52 rounded border border-rule bg-paper shadow-card py-1 z-50">
              <div class="px-3.5 py-2.5 border-b border-rule">
                <p class="text-sm font-medium text-ink truncate">${escapeHtml(user?.name || '—')}</p>
                <p class="text-xs text-muted truncate mt-0.5">${escapeHtml(user?.email || '')}</p>
              </div>
              <a href="/pages/settings.html" class="block px-3.5 py-2 text-sm text-ink hover:bg-rule/40 transition-colors duration-150">Settings</a>
              <button type="button" id="header-logout" class="w-full text-left px-3.5 py-2 text-sm text-ink hover:bg-rule/40 transition-colors duration-150">Sign out</button>
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
