/**
 * Tiny in-memory event bus / store.
 * No persistence. No demo data.
 */

const listeners = new Map();

export function on(event, fn) {
  if (!listeners.has(event)) listeners.set(event, new Set());
  listeners.get(event).add(fn);
  return () => listeners.get(event)?.delete(fn);
}

export function emit(event, payload) {
  const set = listeners.get(event);
  if (!set) return;
  for (const fn of set) {
    try {
      fn(payload);
    } catch (err) {
      console.error(`[state] listener error for "${event}"`, err);
    }
  }
}

const store = Object.create(null);

export function get(key) {
  return store[key];
}

export function set(key, value) {
  store[key] = value;
  emit(`store:${key}`, value);
}

export function del(key) {
  delete store[key];
  emit(`store:${key}`, undefined);
}
