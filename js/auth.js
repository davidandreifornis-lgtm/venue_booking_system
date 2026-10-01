/**
 * Frontend session helpers only.
 * Authorization is enforced by the backend. Client-side role checks
 * exist solely to render the correct interface. Never rely on them
 * for security.
 *
 * ---------------------------------------------------------------------------
 * TEMPORARY — UI/UX testing only. Remove before production.
 * Demo accounts (email / password → role):
 *   admin@demo / admin       → Administrator
 *   hr@demo / hr             → HR
 *   manager@demo / manager   → Manager
 *   employee@demo / employee → Employee
 * Also accepts short form: admin/admin, hr/hr, manager/manager, employee/employee
 * ---------------------------------------------------------------------------
 */

import { getSession, logout as apiLogout } from './api.js';
import { emit } from './state.js';

const DEMO_KEY = 'vbs_demo_session';

/** @type {Record<string, { password: string, user: object }>} */
const DEMO_ACCOUNTS = {
  'admin@demo': {
    password: 'admin',
    user: {
      id: 'demo-admin',
      name: 'Admin User',
      email: 'admin@demo',
      role: 'Administrator',
      department: 'Administration',
    },
  },
  admin: {
    password: 'admin',
    user: {
      id: 'demo-admin',
      name: 'Admin User',
      email: 'admin@demo',
      role: 'Administrator',
      department: 'Administration',
    },
  },
  'hr@demo': {
    password: 'hr',
    user: {
      id: 'demo-hr',
      name: 'HR User',
      email: 'hr@demo',
      role: 'HR',
      department: 'Human Resources',
    },
  },
  hr: {
    password: 'hr',
    user: {
      id: 'demo-hr',
      name: 'HR User',
      email: 'hr@demo',
      role: 'HR',
      department: 'Human Resources',
    },
  },
  'manager@demo': {
    password: 'manager',
    user: {
      id: 'demo-manager',
      name: 'Manager User',
      email: 'manager@demo',
      role: 'Manager',
      department: 'Engineering',
    },
  },
  manager: {
    password: 'manager',
    user: {
      id: 'demo-manager',
      name: 'Manager User',
      email: 'manager@demo',
      role: 'Manager',
      department: 'Engineering',
    },
  },
  'employee@demo': {
    password: 'employee',
    user: {
      id: 'demo-employee',
      name: 'Employee User',
      email: 'employee@demo',
      role: 'Employee',
      department: 'Engineering',
    },
  },
  employee: {
    password: 'employee',
    user: {
      id: 'demo-employee',
      name: 'Employee User',
      email: 'employee@demo',
      role: 'Employee',
      department: 'Engineering',
    },
  },
};

let _session = null;

function readDemoSession() {
  try {
    const raw = sessionStorage.getItem(DEMO_KEY);
    if (!raw) return null;
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

function writeDemoSession(user) {
  sessionStorage.setItem(DEMO_KEY, JSON.stringify(user));
}

function clearDemoSession() {
  sessionStorage.removeItem(DEMO_KEY);
}

/**
 * TEMPORARY: attempt demo login. Returns user object or null.
 */
export function tryDemoLogin(email, password) {
  const key = String(email || '').trim().toLowerCase();
  const entry = DEMO_ACCOUNTS[key];
  if (!entry || entry.password !== password) return null;
  writeDemoSession(entry.user);
  _session = entry.user;
  emit('session:change', _session);
  return entry.user;
}

export async function loadSession() {
  try {
    _session = await getSession();
    if (_session) {
      clearDemoSession();
      emit('session:change', _session);
      return _session;
    }
  } catch {
    // fall through to demo session
  }

  // TEMPORARY: restore demo session for UI testing
  _session = readDemoSession();
  emit('session:change', _session);
  return _session;
}

export function session() {
  return _session;
}

export function role() {
  return _session?.role ?? null;
}

export function isAuthed() {
  return !!_session;
}

export function requireRole(...roles) {
  if (!_session) return false;
  return roles.includes(_session.role);
}

export async function logout() {
  try {
    await apiLogout();
  } catch {
    // ignore network failure on logout
  }
  clearDemoSession();
  _session = null;
  emit('session:change', null);
  window.location.href = '/pages/login.html';
}
