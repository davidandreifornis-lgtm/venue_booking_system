/**
 * Role-aware navigation sidebar. Fixed on desktop, drawer on mobile.
 */

import { session, role, logout } from '../js/auth.js';
import { escapeHtml } from '../js/utils.js';

const NAV = {
  Employee: [
    { href: '/pages/employee-dashboard.html', label: 'Dashboard', icon: 'layout' },
    { href: '/pages/booking-form.html', label: 'New Request', icon: 'plus' },
    { href: '/pages/my-requests.html', label: 'My Requests', icon: 'list' },
    { href: '/pages/calendar.html', label: 'Calendar', icon: 'calendar' },
    { href: '/pages/notifications.html', label: 'Notifications', icon: 'bell' },
  ],
  Manager: [
    { href: '/pages/manager-dashboard.html', label: 'Dashboard', icon: 'layout' },
    { href: '/pages/pending-requests.html', label: 'Pending Requests', icon: 'inbox' },
    { href: '/pages/booking-form.html', label: 'New Request', icon: 'plus' },
    { href: '/pages/my-requests.html', label: 'My Requests', icon: 'list' },
    { href: '/pages/calendar.html', label: 'Calendar', icon: 'calendar' },
    { href: '/pages/notifications.html', label: 'Notifications', icon: 'bell' },
  ],
  HR: [
    { href: '/pages/hr-dashboard.html', label: 'Dashboard', icon: 'layout' },
    { href: '/pages/pending-requests.html', label: 'Pending Decisions', icon: 'inbox' },
    { href: '/pages/all-bookings.html', label: 'All Bookings', icon: 'list' },
    { href: '/pages/calendar.html', label: 'Calendar', icon: 'calendar' },
    { href: '/pages/venue-availability.html', label: 'Venue Availability', icon: 'map' },
    { href: '/pages/notifications.html', label: 'Notifications', icon: 'bell' },
  ],
  Administrator: [
    { href: '/pages/admin-dashboard.html', label: 'Dashboard', icon: 'layout' },
    { href: '/pages/users.html', label: 'Users', icon: 'users' },
    { href: '/pages/departments.html', label: 'Departments', icon: 'building' },
    { href: '/pages/venues.html', label: 'Venues', icon: 'map' },
    { href: '/pages/all-bookings.html', label: 'All Bookings', icon: 'list' },
    { href: '/pages/activity-logs.html', label: 'Activity Logs', icon: 'activity' },
    { href: '/pages/notifications.html', label: 'Notifications', icon: 'bell' },
    { href: '/pages/settings.html', label: 'Settings', icon: 'settings' },
  ],
};

const ICONS = {
  layout: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>',
  plus: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>',
  list: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>',
  calendar: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="1"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
  bell: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>',
  inbox: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z"/></svg>',
  map: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>',
  users: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>',
  building: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/></svg>',
  activity: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
  settings: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>',
};

export function renderSidebar(container) {
  const r = role() || 'Employee';
  const items = NAV[r] || NAV.Employee;
  const path = window.location.pathname;
  const user = session();

  const links = items
    .map((item) => {
      const file = item.href.split('/').pop();
      const active = path.endsWith(file) || path.includes(file.replace('.html', ''));
      return `
        <a href="${item.href}"
           class="group flex items-center gap-3 px-3 py-2.5 text-[13px] rounded-sm transition-colors duration-150
           ${active
             ? 'bg-white/[0.08] text-paper font-medium'
             : 'text-paper/55 hover:text-paper hover:bg-white/[0.04]'}">
          <span class="shrink-0 ${active ? 'text-paper' : 'text-paper/40 group-hover:text-paper/70'} transition-colors duration-150">${ICONS[item.icon] || ''}</span>
          <span class="tracking-wide">${escapeHtml(item.label)}</span>
          ${active ? '<span class="ml-auto w-1 h-1 rounded-full bg-accent"></span>' : ''}
        </a>
      `;
    })
    .join('');

  container.innerHTML = `
    <div class="flex flex-col h-full">
      <div class="px-5 pt-7 pb-6">
        <a href="/" class="block">
          <p class="font-display text-[22px] font-semibold tracking-[-0.035em] text-paper leading-none">Venue</p>
          <p class="mt-1.5 text-[10px] uppercase tracking-[0.14em] text-paper/35 font-medium">Booking System</p>
        </a>
      </div>

      <div class="px-3 mb-2">
        <p class="px-3 text-[10px] uppercase tracking-[0.12em] text-paper/30 mb-1.5">Navigation</p>
      </div>

      <nav class="flex-1 px-3 space-y-0.5 overflow-y-auto pb-4">${links}</nav>

      <div class="mt-auto border-t border-white/[0.06] px-3 py-4">
        <div class="flex items-center gap-3 px-3 py-2.5 mb-1">
          <div class="w-8 h-8 rounded-sm bg-white/10 flex items-center justify-center text-[11px] font-medium text-paper tracking-wide shrink-0">
            ${initials(user?.name)}
          </div>
          <div class="min-w-0 flex-1">
            <p class="text-[13px] text-paper truncate leading-tight">${escapeHtml(user?.name || '—')}</p>
            <p class="text-[10px] text-paper/35 uppercase tracking-[0.1em] mt-0.5">${escapeHtml(r)}</p>
          </div>
        </div>
        <button type="button" id="sidebar-logout"
          class="w-full flex items-center gap-3 px-3 py-2.5 text-[13px] text-paper/45 hover:text-paper hover:bg-white/[0.04] rounded-sm transition-colors duration-150">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
          <span class="tracking-wide">Sign out</span>
        </button>
      </div>
    </div>
  `;

  container.querySelector('#sidebar-logout')?.addEventListener('click', () => logout());
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

export function openDrawer() {
  document.getElementById('sidebar-drawer')?.classList.remove('translate-x-[-100%]');
  document.getElementById('sidebar-backdrop')?.classList.remove('opacity-0', 'pointer-events-none');
}

export function closeDrawer() {
  document.getElementById('sidebar-drawer')?.classList.add('translate-x-[-100%]');
  document.getElementById('sidebar-backdrop')?.classList.add('opacity-0', 'pointer-events-none');
}
