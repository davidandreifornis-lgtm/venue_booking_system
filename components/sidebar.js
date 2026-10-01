/**
 * Full-height fixed sidebar. Role-based nav. No user block (user lives in topbar).
 */

import { role } from '../js/auth.js';
import { escapeHtml } from '../js/utils.js';

const NAV = {
  Employee: [
    { section: 'Overview' },
    { href: 'employee-dashboard.html', label: 'Dashboard', icon: 'dash' },
    { href: 'booking-form.html', label: 'New Request', icon: 'plus' },
    { href: 'my-requests.html', label: 'My Requests', icon: 'list' },
    { href: 'calendar.html', label: 'Calendar', icon: 'cal' },
    { section: 'Account' },
    { href: 'notifications.html', label: 'Notifications', icon: 'bell' },
  ],
  Manager: [
    { section: 'Overview' },
    { href: 'manager-dashboard.html', label: 'Dashboard', icon: 'dash' },
    { href: 'pending-requests.html', label: 'Pending Requests', icon: 'inbox' },
    { href: 'booking-form.html', label: 'New Request', icon: 'plus' },
    { href: 'my-requests.html', label: 'My Requests', icon: 'list' },
    { href: 'calendar.html', label: 'Calendar', icon: 'cal' },
    { section: 'Account' },
    { href: 'notifications.html', label: 'Notifications', icon: 'bell' },
  ],
  HR: [
    { section: 'Overview' },
    { href: 'hr-dashboard.html', label: 'Dashboard', icon: 'dash' },
    { href: 'pending-requests.html', label: 'Pending Decisions', icon: 'inbox' },
    { href: 'all-bookings.html', label: 'All Bookings', icon: 'list' },
    { href: 'calendar.html', label: 'Calendar', icon: 'cal' },
    { href: 'venue-availability.html', label: 'Venue Availability', icon: 'map' },
    { section: 'Account' },
    { href: 'notifications.html', label: 'Notifications', icon: 'bell' },
  ],
  Administrator: [
    { section: 'Overview' },
    { href: 'admin-dashboard.html', label: 'Dashboard', icon: 'dash' },
    { section: 'Manage' },
    { href: 'users.html', label: 'Users', icon: 'users' },
    { href: 'departments.html', label: 'Departments', icon: 'building' },
    { href: 'venues.html', label: 'Venues', icon: 'map' },
    { href: 'all-bookings.html', label: 'All Bookings', icon: 'list' },
    { section: 'System' },
    { href: 'activity-logs.html', label: 'Activity Logs', icon: 'activity' },
    { href: 'notifications.html', label: 'Notifications', icon: 'bell' },
    { href: 'settings.html', label: 'Settings', icon: 'settings' },
  ],
};

const ICONS = {
  dash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>',
  plus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M12 5v14M5 12h14"/></svg>',
  list: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>',
  cal: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
  bell: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>',
  inbox: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z"/></svg>',
  map: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>',
  users: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>',
  building: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/></svg>',
  activity: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
  settings: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>',
};

export function renderSidebar(container) {
  const r = role() || 'Employee';
  const items = NAV[r] || NAV.Employee;
  const file = window.location.pathname.split('/').pop() || '';

  let html = `
    <div class="sidebar-top">
      <div>
        <a href="index.html" class="brand-name">Venue</a>
        <span class="brand-sub">Booking System</span>
      </div>
    </div>
    <div class="sidebar-nav">
  `;

  items.forEach((item) => {
    if (item.section) {
      html += `<div class="nav-section">${escapeHtml(item.section)}</div>`;
      return;
    }
    const active = file === item.href ? 'active' : '';
    html += `
      <a href="${item.href}" class="${active}">
        ${ICONS[item.icon] || ''}
        <span>${escapeHtml(item.label)}</span>
      </a>
    `;
  });

  html += `</div>`;
  container.innerHTML = html;
}

export function openDrawer() {
  document.getElementById('sidebar')?.classList.add('is-open');
  document.getElementById('sidebar-backdrop')?.classList.add('is-open');
}

export function closeDrawer() {
  document.getElementById('sidebar')?.classList.remove('is-open');
  document.getElementById('sidebar-backdrop')?.classList.remove('is-open');
}
