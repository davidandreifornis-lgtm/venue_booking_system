import * as api from './api.js';

export function loadUsers(params) {
  return api.getUsers(params);
}

export function loadUser(id) {
  return api.getUser(id);
}

export function createUser(payload) {
  return api.createUser(payload);
}

export function updateUser(id, payload) {
  return api.updateUser(id, payload);
}

export function deactivateUser(id) {
  return api.deactivateUser(id);
}
