/**
 * Backend seam. All calls hit /api/... and throw on failure.
 * No mock data. No fake resolves.
 * Drop in a real REST backend later without changing page markup.
 */

const BASE = '/api';

async function request(method, path, body = null) {
  const opts = {
    method,
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    credentials: 'same-origin',
  };
  if (body !== null) opts.body = JSON.stringify(body);

  let res;
  try {
    res = await fetch(`${BASE}${path}`, opts);
  } catch (err) {
    const e = new Error('Network error: unable to reach the server.');
    e.cause = err;
    e.status = 0;
    throw e;
  }

  if (!res.ok) {
    let message = `Request failed (${res.status})`;
    try {
      const data = await res.json();
      if (data && data.message) message = data.message;
    } catch (_) {}
    const e = new Error(message);
    e.status = res.status;
    throw e;
  }

  if (res.status === 204) return null;
  return res.json();
}

// ── Auth ────────────────────────────────────────────────
export function getSession() {
  return request('GET', '/auth/session');
}

export function login(email, password) {
  return request('POST', '/auth/login', { email, password });
}

export function logout() {
  return request('POST', '/auth/logout');
}

// ── Bookings ────────────────────────────────────────────
export function getBookings(params = {}) {
  const q = new URLSearchParams(params).toString();
  return request('GET', `/bookings${q ? `?${q}` : ''}`);
}

export function getBooking(id) {
  return request('GET', `/bookings/${id}`);
}

export function createBooking(payload) {
  return request('POST', '/bookings', payload);
}

export function updateBooking(id, payload) {
  return request('PUT', `/bookings/${id}`, payload);
}

export function validateBooking(id, payload) {
  return request('POST', `/bookings/${id}/validate`, payload);
}

export function approveBooking(id, payload) {
  return request('POST', `/bookings/${id}/approve`, payload);
}

export function declineBooking(id, payload) {
  return request('POST', `/bookings/${id}/decline`, payload);
}

export function rescheduleBooking(id, payload) {
  return request('POST', `/bookings/${id}/reschedule`, payload);
}

export function cancelBooking(id, payload) {
  return request('POST', `/bookings/${id}/cancel`, payload);
}

// ── Venues ──────────────────────────────────────────────
export function getVenues(params = {}) {
  const q = new URLSearchParams(params).toString();
  return request('GET', `/venues${q ? `?${q}` : ''}`);
}

export function getVenue(id) {
  return request('GET', `/venues/${id}`);
}

export function createVenue(payload) {
  return request('POST', '/venues', payload);
}

export function updateVenue(id, payload) {
  return request('PUT', `/venues/${id}`, payload);
}

export function deactivateVenue(id) {
  return request('POST', `/venues/${id}/deactivate`);
}

// ── Users ───────────────────────────────────────────────
export function getUsers(params = {}) {
  const q = new URLSearchParams(params).toString();
  return request('GET', `/users${q ? `?${q}` : ''}`);
}

export function getUser(id) {
  return request('GET', `/users/${id}`);
}

export function createUser(payload) {
  return request('POST', '/users', payload);
}

export function updateUser(id, payload) {
  return request('PUT', `/users/${id}`, payload);
}

export function deactivateUser(id) {
  return request('POST', `/users/${id}/deactivate`);
}

// ── Departments ─────────────────────────────────────────
export function getDepartments(params = {}) {
  const q = new URLSearchParams(params).toString();
  return request('GET', `/departments${q ? `?${q}` : ''}`);
}

export function createDepartment(payload) {
  return request('POST', '/departments', payload);
}

export function updateDepartment(id, payload) {
  return request('PUT', `/departments/${id}`, payload);
}

export function assignManager(id, managerId) {
  return request('POST', `/departments/${id}/assign-manager`, { managerId });
}

// ── Notifications ───────────────────────────────────────
export function getNotifications(params = {}) {
  const q = new URLSearchParams(params).toString();
  return request('GET', `/notifications${q ? `?${q}` : ''}`);
}

export function markNotificationRead(id) {
  return request('POST', `/notifications/${id}/read`);
}

// ── Activity Logs ───────────────────────────────────────
export function getActivityLogs(params = {}) {
  const q = new URLSearchParams(params).toString();
  return request('GET', `/activity-logs${q ? `?${q}` : ''}`);
}
