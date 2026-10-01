import * as api from './api.js';

export function loadNotifications(params) {
  return api.getNotifications(params);
}

export function markRead(id) {
  return api.markNotificationRead(id);
}
