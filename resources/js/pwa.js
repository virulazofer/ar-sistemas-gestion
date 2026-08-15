/**
 * Registro PWA + aviso offline (sin ops financieras offline).
 */
export function registerPwa() {
  if (!('serviceWorker' in navigator)) {
    return;
  }

  // Solo en contextos seguros (HTTPS o localhost)
  const isSecure = window.isSecureContext;
  if (!isSecure) {
    return;
  }

  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {
      /* SW opcional: no romper la app */
    });
  });
}

export function initOfflineBanner() {
  const banner = document.getElementById('ar-offline-banner');
  if (!banner) {
    return;
  }

  const sync = () => {
    const offline = !navigator.onLine;
    banner.hidden = !offline;
    banner.setAttribute('aria-hidden', offline ? 'false' : 'true');
  };

  window.addEventListener('online', sync);
  window.addEventListener('offline', sync);
  sync();
}
