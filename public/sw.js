/* AR Sistemas — Service Worker conservador (12A)
 * Cachea SOLO estáticos versionados/seguros.
 * NUNCA cachea auth, finanzas, documentos, APIs ni POST.
 */
const CACHE_NAME = 'ar-static-v12a-3-ui-hide';
const PRECACHE = [
  '/offline.html',
  '/manifest.webmanifest',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
];

const SENSITIVE_PATH_PREFIXES = [
  '/login',
  '/logout',
  '/documentos',
  '/movimientos',
  '/clientes',
  '/proveedores',
  '/cuentas',
  '/cobros',
  '/cargos',
  '/ventas',
  '/compras',
  '/stock',
  '/ot',
  '/abonos',
  '/presupuestos',
  '/importaciones',
  '/reportes',
  '/users',
  '/permissions',
  '/settings',
  '/audit',
  '/plan-de-cuentas',
  '/buscar',
  '/profile',
  '/dashboard',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

function isSensitive(url) {
  const path = url.pathname;
  if (path.startsWith('/api')) return true;
  return SENSITIVE_PATH_PREFIXES.some((p) => path === p || path.startsWith(p + '/'));
}

function isStaticAsset(url) {
  if (url.origin !== self.location.origin) return false;
  const path = url.pathname;
  if (path.startsWith('/build/')) return true;
  if (path.startsWith('/icons/')) return true;
  if (path === '/manifest.webmanifest') return true;
  if (path === '/offline.html') return true;
  if (path === '/sw.js') return false;
  return /\.(?:css|js|png|jpg|jpeg|webp|svg|woff2?)$/i.test(path);
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') {
    return; // nunca cachear POST/PUT/etc.
  }

  let url;
  try {
    url = new URL(req.url);
  } catch {
    return;
  }

  if (url.origin !== self.location.origin) {
    return;
  }

  if (isSensitive(url)) {
    return; // network-only para rutas sensibles
  }

  if (!isStaticAsset(url)) {
    // Navegación HTML: network-first con offline fallback (sin cachear la respuesta auth)
    if (req.mode === 'navigate') {
      event.respondWith(
        fetch(req).catch(() => caches.match('/offline.html'))
      );
    }
    return;
  }

  event.respondWith(
    caches.open(CACHE_NAME).then(async (cache) => {
      const cached = await cache.match(req);
      const network = fetch(req)
        .then((res) => {
          if (res && res.ok) {
            cache.put(req, res.clone());
          }
          return res;
        })
        .catch(() => cached);
      return cached || network;
    })
  );
});
