/**
 * Frontend session helpers.
 * Authorization is enforced by the PHP backend + SQL Server.
 * Client role is only for UI (nav, labels) — never for security.
 */

import { getSession, logout as apiLogout } from './api.js';
import { emit } from './state.js';

let _session = null;

/**
 * Load session from the server (cookie session).
 * Returns the user object or null.
 */
export async function loadSession() {
  try {
    _session = await getSession();
    // API may return null body when not logged in
    if (_session === null || _session === undefined) {
      _session = null;
    }
  } catch {
    _session = null;
  }
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

/**
 * After successful API login, store the returned user in memory.
 * The PHP session cookie is the source of truth on the next loadSession().
 */
export function setSession(user) {
  _session = user || null;
  emit('session:change', _session);
  return _session;
}

export async function logout() {
  try {
    await apiLogout();
  } catch {
    // Still clear local session if network fails
  }
  _session = null;
  emit('session:change', null);
  window.location.href = 'login.html';
}
