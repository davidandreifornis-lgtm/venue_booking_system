/**
 * Bootstrap: session + shared shell (sidebar fixed full-height + main content).
 * Layout matches enterprise reference (sidebar fixed, main-content margin-left).
 */

import { loadSession, isAuthed, role } from './auth.js';
import { renderSidebar, openDrawer, closeDrawer } from '../components/sidebar.js';
import { renderHeader } from '../components/header.js';

const PUBLIC = ['login.html'];

function isPublicPath(path) {
  return PUBLIC.some((p) => path.endsWith(p));
}

export async function boot() {
  await loadSession();
  const path = window.location.pathname;

  if (!isPublicPath(path) && !isAuthed()) {
    window.location.href = 'login.html';
    return;
  }
  if (isPublicPath(path) && isAuthed()) {
    redirectToDashboard(role());
    return;
  }
  if (!isPublicPath(path)) {
    injectShell();
  }

  if (typeof window.__pageInit === 'function') {
    try {
      await window.__pageInit();
    } catch (err) {
      console.error('[app] page init', err);
    }
  }
}

function injectShell() {
  const app = document.getElementById('app');
  if (!app) return;

  // Capture page content before rebuilding
  const pageNodes = Array.from(app.childNodes);

  // Clear body-level layout: build shell as siblings of structure
  // Structure:
  //   backdrop
  //   aside.sidebar
  //   main.main-content
  //     .topbar
  //     .page-body  ← page content

  const backdrop = document.createElement('div');
  backdrop.className = 'sidebar-backdrop';
  backdrop.id = 'sidebar-backdrop';
  backdrop.addEventListener('click', closeDrawer);

  const sidebar = document.createElement('nav');
  sidebar.className = 'sidebar';
  sidebar.id = 'sidebar';
  sidebar.setAttribute('aria-label', 'Main navigation');

  const main = document.createElement('main');
  main.className = 'main-content';
  main.id = 'mainContent';

  const topbar = document.createElement('div');
  topbar.className = 'topbar';
  topbar.id = 'app-topbar';

  const pageBody = document.createElement('div');
  pageBody.className = 'page-body';
  pageBody.id = 'page-body';

  pageNodes.forEach((n) => pageBody.appendChild(n));

  main.appendChild(topbar);
  main.appendChild(pageBody);

  // Replace #app with shell pieces on body
  app.replaceWith(backdrop, sidebar, main);

  renderSidebar(sidebar);
  renderHeader(topbar);

  // Ensure body has no margin issues
  document.body.style.margin = '0';
}

function redirectToDashboard(r) {
  const map = {
    Employee: 'employee-dashboard.html',
    Manager: 'manager-dashboard.html',
    HR: 'hr-dashboard.html',
    Administrator: 'admin-dashboard.html',
  };
  window.location.href = map[r] || 'login.html';
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}
