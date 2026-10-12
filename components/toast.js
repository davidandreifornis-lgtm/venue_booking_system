let root = null;
const FLASH_KEY = 'vb_flash';

function ensureRoot() {
  if (root) return root;
  root = document.createElement('div');
  root.className = 'toast-root';
  root.setAttribute('aria-live', 'polite');
  document.body.appendChild(root);
  return root;
}

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;');

export function toast(message, type = 'info', duration) {
  const kind = type === 'error' ? 'error' : type === 'success' ? 'success' : 'info';
  const icon = kind === 'success' ? '✓' : kind === 'error' ? '!' : 'i';
  const ms = duration || (kind === 'success' ? 7000 : 5000);
  const el = document.createElement('div');
  el.className = `toast toast-${kind}`;
  el.setAttribute('role', kind === 'error' ? 'alert' : 'status');
  el.innerHTML = `<span style="flex:none;font-weight:700;width:18px;height:18px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;color:#fff;background:${kind === 'success' ? 'var(--success)' : kind === 'error' ? 'var(--danger)' : 'var(--accent)'}">${icon}</span>
    <span style="flex:1">${esc(message)}</span>
    <button type="button" aria-label="Dismiss" style="border:none;background:transparent;cursor:pointer;color:#6c757d">×</button>`;
  ensureRoot().appendChild(el);
  const remove = () => el.remove();
  el.querySelector('button').addEventListener('click', remove);
  setTimeout(remove, ms);
}

/** Queue a message to show on the NEXT page load (use before location.reload() / redirects). */
export function flash(message, type = 'success') {
  try {
    sessionStorage.setItem(FLASH_KEY, JSON.stringify({ message, type }));
  } catch {
    /* storage blocked: message is simply skipped */
  }
}

export function showFlash() {
  try {
    const raw = sessionStorage.getItem(FLASH_KEY);
    if (!raw) return;
    sessionStorage.removeItem(FLASH_KEY);
    const { message, type } = JSON.parse(raw);
    if (message) toast(message, type);
  } catch {
    /* ignore */
  }
}
