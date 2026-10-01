/**
 * Booking domain helpers (UI-facing).
 */

import * as api from './api.js';

export async function loadBookings(params = {}) {
  return api.getBookings(params);
}

export async function loadBooking(id) {
  return api.getBooking(id);
}

export async function submitBooking(payload) {
  return api.createBooking(payload);
}

export async function validateRequest(id, payload) {
  return api.validateBooking(id, payload);
}

export async function approveRequest(id, payload) {
  return api.approveBooking(id, payload);
}

export async function declineRequest(id, payload) {
  return api.declineBooking(id, payload);
}

export async function rescheduleRequest(id, payload) {
  return api.rescheduleBooking(id, payload);
}

export async function cancelRequest(id, payload) {
  return api.cancelBooking(id, payload);
}
