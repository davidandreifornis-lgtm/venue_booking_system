let root = null;
function ensureRoot() {
  if (root) return root;
  root = document.createElement('div');
  root.className = 'toast-root';
  document.body.appendChild(root);
  return root;
}
export function toast(message, type = 'info', duration = 4000) {
  const el = document.createElement('div');
  el.className = `toast toast-${type === 'error' ? 'error' : type === 'success' ? 'success' : 'info'}`;
  el.innerHTML = `<span style="flex:1">${String(message).replace(/</g,'&lt;')}</span>
    <button type="button" aria-label="Dismiss" style="border:none;background:transparent;cursor:pointer;color:#6c757d">×</button>`;
  ensureRoot().appendChild(el);
  const remove = () => el.remove();
  el.querySelector('button').addEventListener('click', remove);
  setTimeout(remove, duration);
}
