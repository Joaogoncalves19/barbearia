/* Service Worker do PWA da barbearia.
 * Servido de /js/ mas com escopo ampliado para a raiz do app via o cabeçalho
 * Service-Worker-Allowed (ver js/.htaccess). Todos os caminhos são resolvidos
 * a partir de self.registration.scope, então funcionam em subpasta ou na raiz.
 *
 * Estratégia:
 *  - Navegações (páginas PHP): network-first -> nunca mostra conta/agenda desatualizada;
 *    cai para cache/offline só quando sem rede.
 *  - Estáticos (css/js/img/fontes): stale-while-revalidate.
 *  - Nunca intercepta POST nem requisições cross-origin.
 */
const CACHE = 'barbearia-v4';

function appUrl(path) {
  // resolve relativo ao escopo (raiz do app), não à localização do sw.js (/js/)
  return new URL(path, self.registration.scope).toString();
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) =>
      cache.addAll([
        appUrl('uploads/pwa-icon-192.png'),
        appUrl('uploads/pwa-icon-512.png'),
        appUrl('uploads/pwa-maskable-512.png')
      ])
    ).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

function isStatic(url) {
  return /\.(css|js|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|mp4)$/i.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Estáticos: stale-while-revalidate
  if (isStatic(url)) {
    event.respondWith(
      caches.open(CACHE).then((cache) =>
        cache.match(req).then((cached) => {
          const network = fetch(req)
            .then((res) => {
              if (res && res.status === 200) cache.put(req, res.clone());
              return res;
            })
            .catch(() => cached);
          return cached || network;
        })
      )
    );
    return;
  }

  // Navegações / páginas: network-first
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone();
          caches.open(CACHE).then((cache) => cache.put(req, copy)).catch(() => {});
          return res;
        })
        .catch(() =>
          caches.match(req).then((cached) => cached || caches.match(appUrl('cliente.php')))
        )
    );
  }
});
