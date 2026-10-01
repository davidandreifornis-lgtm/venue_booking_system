/**
 * Top bar: page title, mobile menu, notifications, user dropdown only.
 * User is NOT duplicated in the sidebar.
 */

import { session, role, logout } from '../js/auth.js';
import { openDrawer } from './sidebar.js';
import { escapeHtml } from '../js/utils.js';

export function renderHeader(container) {
  const title = document.body.dataset.pageTitle || 'Dashboard';
  const user = session();
  const r = role() || '';

  container.innerHTML = `
    <div class="d-flex align-items-center gap-2" style="display:flex;align-items:center;gap:10px;min-width:0;flex:1;">
      <button type="button" class="topbar-menu-btn" id="header-menu" aria-label="Open menu">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
      </button>
      <h1 class="topbar-title">${escapeHtml(title)}</h1>
    </div>
    <div class="topbar-right">
      <span class="topbar-meta">${escapeHtml(r)}</span>
      <div class="topbar-divider"></div>
      <a href="notifications.html" class="btn btn-ghost btn-sm" aria-label="Notifications" title="Notifications">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
      </a>
      <div class="user-dropdown-wrap">
        <button type="button" class="user-btn" id="user-menu-btn">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12a5 5 0 100-10 5 5 0 000 10zm0 2c-5 0-9 2.5-9 5.5V22h18v-2.5c0-3-4-5.5-9-5.5z"/></svg>
          ${escapeHtml(user?.name || user?.email || 'User')}
        </button>
        <div class="user-menu" id="user-menu">
          <div class="user-menu-header">
            <strong>${escapeHtml(user?.name || '—')}</strong>
            <span>${escapeHtml(user?.email || '')} · ${escapeHtml(r)}</span>
          </div>
          <a href="settings.html">Settings</a>
          <button type="button" id="header-logout">Sign out</button>
        </div>
      </div>
    </div>
  `;

  container.querySelector('#header-menu')?.addEventListener('click', openDrawer);
  container.querySelector('#header-logout')?.addEventListener('click', () => logout());

  const btn = container.querySelector('#user-menu-btn');
  const menu = container.querySelector('#user-menu');
  btn?.addEventListener('click', (e) => {
    e.stopPropagation();
    menu?.classList.toggle('is-open');
  });
  document.addEventListener('click', () => menu?.classList.remove('is-open'));
}
