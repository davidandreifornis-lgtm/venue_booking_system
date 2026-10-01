import * as api from './api.js';

export function loadDepartments(params) {
  return api.getDepartments(params);
}

export function createDepartment(payload) {
  return api.createDepartment(payload);
}

export function updateDepartment(id, payload) {
  return api.updateDepartment(id, payload);
}

export function assignManager(id, managerId) {
  return api.assignManager(id, managerId);
}
