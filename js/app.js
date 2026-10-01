/**
 * Bootstrap: load session, inject shell, dispatch page init.
 */

import { loadSession, isAuthed, role } from './auth.js';
import { renderSidebar, closeDrawer } from '../components/sidebar.js';
import { renderHeader } from '../components/header.js';

const PUBLIC = ['/pages/login.html', '/login.html'];

export async function boot() {
  await loadSession();

  const path = window.location.pathname;
  const isPublic = PUBLIC.some((p) => path.endsWith(p) || path.includes('login'));

  if (!isPublic && !isAuthed()) {
    window.location.href = '/pages/login.html';
    return;
  }

  if (isPublic && isAuthed()) {
    redirectToDashboard(role());
    return;
  }

  if (!isPublic) {
    injectShell();
  }

  const init = window.__pageInit;
  if (typeof init === 'function') {
    try {
      await init();
    } catch (err) {
      console.error('[app] page init failed', err);
    }
  }
}

function injectShell() {
  const app = document.getElementById('app');
  if (!app) return;

  const backdrop = document.createElement('div');
  backdrop.id = 'sidebar-backdrop';
  backdrop.className = 'fixed inset-0 bg-ink/50 z-40 lg:hidden opacity-0 pointer-events-none transition-opacity duration-200';
  backdrop.addEventListener('click', closeDrawer);
  document.body.appendChild(backdrop);

  const drawer = document.createElement('aside');
  drawer.id = 'sidebar-drawer';
  drawer.className =
    'fixed inset-y-0 left-0 w-64 bg-ink z-50 transform translate-x-[-100%] lg:translate-x-0 transition-transform duration-200 ease-out lg:static lg:z-auto';
  document.body.insertBefore(drawer, app);

  app.className = 'lg:pl-64 min-h-screen flex flex-col bg-paper';

  const headerEl = document.createElement('div');
  headerEl.id = 'app-header';
  app.prepend(headerEl);

  const content = document.createElement('main');
  content.id = 'app-content';
  content.className = 'flex-1 w-full px-4 sm:px-6 lg:px-8 py-7 sm:py-9 max-w-7xl';

  while (app.childNodes.length > 1) {
    content.appendChild(app.childNodes[1]);
  }
  app.appendChild(content);

  renderSidebar(drawer);
  renderHeader(headerEl);

  const mq = window.matchMedia('(min-width: 1024px)');
  function sync() {
    if (mq.matches) {
      drawer.classList.remove('translate-x-[-100%]');
      backdrop.classList.add('opacity-0', 'pointer-events-none');
    } else {
      drawer.classList.add('translate-x-[-100%]');
    }
  }
  mq.addEventListener('change', sync);
  sync();
}

function redirectToDashboard(r) {
  const map = {
    Employee: '/pages/employee-dashboard.html',
    Manager: '/pages/manager-dashboard.html',
    HR: '/pages/hr-dashboard.html',
    Administrator: '/pages/admin-dashboard.html',
  };
  window.location.href = map[r] || '/pages/login.html';
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}
