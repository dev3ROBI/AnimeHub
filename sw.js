/**
 * KitsuPlay service worker — offline shell + aggressive static caching.
 *
 * Lives at the APP ROOT (not assets/pwa/) so its default scope is the whole
 * app: that works both locally (/AnimeHub/) and on production (/) with no
 * Service-Worker-Allowed header. All URLs below are relative, resolved
 * against this script's location.
 *
 * Strategies:
 *   navigations  → network-first, cache updated on success, offline.html fallback
 *   static       → cache-first keyed by the FULL url: asset URLs carry
 *                  ?v=<mtime>-<size>, so editing a file changes the URL and a
 *                  new URL is always a miss (that is what stops a pre-edit
 *                  sheet from being served under the new one). The
 *                  ignoreSearch match is consulted only when the network is
 *                  gone, so offline still renders. VERSION bump purges both
 *                  caches on deploy.
 *   APIs (.php)  → network-first, exact-URL cache fallback when offline
 *   media/video  → not intercepted at all (no range/HLS corruption)
 */
const VERSION = 'v9'; // v9: premium trending ranks, Upcoming day tabs, My Pulse panel
const SHELL_CACHE = 'kp-shell-' + VERSION;
const RUNTIME_CACHE = 'kp-runtime-' + VERSION;
const OFFLINE_URL = './assets/pwa/offline.html';

// Best-effort shell list — the install handler fetches these one by one, so a
// single miss skips that entry instead of bricking the whole install.
// critical.css is NOT here: it is inlined into every page (kp_inline_css).
// .min names because kp_asset() serves the minified build when fresh.
const PRECACHE_URLS = [
  './',
  './assets/css/fonts.css',
  './assets/css/fonts.min.css',
  './assets/css/nav_style.min.css',
  './assets/css/home.min.css',
  './assets/js/hero-slider.min.js',
  './assets/js/card-preview.min.js',
  './assets/js/home-sections.min.js',
  './assets/js/genre-scroll.min.js',
  './assets/js/countdown.min.js',
  './assets/pwa/install-prompt.min.js',
  './assets/pwa/install-prompt.css',
  './assets/fonts/poppins-400.woff2',
  './assets/fonts/poppins-600.woff2',
  './assets/fonts/firacode-400.woff2',
  './assets/fonts/tangerine-400.woff2',
  './assets/fonts/tangerine-700.woff2',
  './assets/pwa/offline.html'
];

self.addEventListener('install', (event) => {
  // Per-URL tolerant precache: addAll() is all-or-nothing, so a single 404
  // (e.g. fonts.min.css before the first minify run) would silently leave the
  // shell cache empty. Here every miss is skipped and the rest still land.
  event.waitUntil(
    caches.open(SHELL_CACHE).then((cache) =>
      Promise.all(
        PRECACHE_URLS.map((url) =>
          fetch(url, { credentials: 'same-origin' })
            .then((res) => (res.ok ? cache.put(url, res) : undefined))
            .catch(() => undefined)
        )
      )
    )
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((k) => k !== SHELL_CACHE && k !== RUNTIME_CACHE)
          .map((k) => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

/** Same-origin + artwork CDNs: content-addressed, safe cache-first. */
function isCacheableAsset(url) {
  if (url.origin === self.location.origin) {
    return /\.(css|js|woff2?|png|jpe?g|gif|webp|avif|svg|ico|json)$/.test(url.pathname);
  }
  return url.hostname === 's4.anilist.co' || url.hostname === 'image.tmdb.org';
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  // Byte-range / media traffic: never touch (HLS segments, MP4 seeks).
  if (request.headers.has('range') ||
      /\.(m3u8|ts|mp4|webm|m4v|mp3|m4a)$/.test(url.pathname)) {
    return;
  }

  // Pages: network-first so content is never stale, offline.html when dark.
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((res) => {
          if (res && res.ok) {
            const clone = res.clone();
            caches.open(RUNTIME_CACHE).then((c) => c.put(request, clone));
          }
          return res;
        })
        .catch(() =>
          caches.match(request).then((hit) => hit || caches.match(OFFLINE_URL))
        )
    );
    return;
  }

  // APIs: network-first with exact-URL fallback (notification counts etc.).
  if (url.origin === self.location.origin && url.pathname.endsWith('.php')) {
    event.respondWith(
      fetch(request)
        .then((res) => {
          if (res && res.ok) {
            const clone = res.clone();
            caches.open(RUNTIME_CACHE).then((c) => c.put(request, clone));
          }
          return res;
        })
        .catch(() => caches.match(request).then((hit) => hit || Response.error()))
    );
    return;
  }

  // Static + artwork: cache-first, keyed by the exact URL. Matching with
  // ignoreSearch used to hand back the previous file for every new ?v= URL,
  // which is how a rebuilt stylesheet kept arriving as the pre-edit one. A
  // changed file has a changed URL, so it misses and is fetched; the loose
  // match is only used as an offline fallback (any version beats nothing).
  if (isCacheableAsset(url)) {
    event.respondWith(
      caches.match(request).then((cached) => {
        if (cached) return cached;
        return fetch(request)
          .then((res) => {
            if (res && (res.ok || res.type === 'opaque')) {
              const clone = res.clone();
              caches.open(RUNTIME_CACHE).then((c) => c.put(request, clone));
            }
            return res;
          })
          .catch(() =>
            caches
              .match(request, { ignoreSearch: true })
              .then((hit) => hit || Response.error())
          );
      })
    );
  }
  // Everything else (cdnjs, unpkg, jQuery…) passes through untouched.
});
