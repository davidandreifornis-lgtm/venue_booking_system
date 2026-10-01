import * as api from './api.js';

export function loadVenues(params) {
  return api.getVenues(params);
}

export function loadVenue(id) {
  return api.getVenue(id);
}

export function createVenue(payload) {
  return api.createVenue(payload);
}

export function updateVenue(id, payload) {
  return api.updateVenue(id, payload);
}

export function deactivateVenue(id) {
  return api.deactivateVenue(id);
}
