/* Service worker Sistem SUSENAS (Inc 162) — Android & iPhone.
 * - Berkas tampilan (CSS/JS/ikon/huruf): disimpan, diperbarui diam-diam (stale-while-revalidate).
 * - Halaman petugas (Tugas Saya, entri, rincian sampel, dashboard): ambil dari jaringan dulu;
 *   bila tidak ada sinyal pakai salinan terakhir; bila belum pernah dibuka → offline.html.
 * - POST (simpan isian, kirim) TIDAK pernah lewat cache; isian tanpa sinyal diantrekan di HP oleh app.js.
 * Ganti VERSI bila berkas inti berubah besar. */
const VERSI = "v1";
const STATIC = "susenas-static-" + VERSI;
const PAGES = "susenas-pages-" + VERSI;
const PRECACHE = ["/offline.html", "/css/app.css", "/js/app.js", "/icons/icon-192.png", "/manifest.webmanifest"];
const PAGE_RE = /^\/(monitoring(\/tugas|\/sampel\/\d+)?|entri\/\d+)\/?$/;

self.addEventListener("install", (e) => {
  e.waitUntil(caches.open(STATIC).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener("activate", (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith("susenas-") && k !== STATIC && k !== PAGES).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener("message", (e) => {
  // keluar akun: hapus salinan halaman (berisi data rumah tangga)
  if (e.data === "clear-pages") e.waitUntil(caches.delete(PAGES));
});

function staleWhileRevalidate(req) {
  return caches.open(STATIC).then((c) =>
    c.match(req, { ignoreSearch: true }).then((hit) => {
      const net = fetch(req).then((res) => {
        if (res && (res.ok || res.type === "opaque")) c.put(req, res.clone());
        return res;
      }).catch(() => hit);
      return hit || net;
    })
  );
}

self.addEventListener("fetch", (e) => {
  const req = e.request;
  if (req.method !== "GET") return;
  const url = new URL(req.url);

  if (url.origin !== location.origin) {
    if (/fonts\.(googleapis|gstatic)\.com$/.test(url.hostname)) e.respondWith(staleWhileRevalidate(req));
    return;
  }
  if (/^\/(css|js|icons)\//.test(url.pathname) || url.pathname === "/manifest.webmanifest") {
    e.respondWith(staleWhileRevalidate(req));
    return;
  }
  if (req.mode === "navigate") {
    const cacheable = PAGE_RE.test(url.pathname);
    e.respondWith(
      fetch(req).then((res) => {
        // jangan simpan halaman login / alihan (sesi habis) sebagai salinan
        if (cacheable && res.ok && !res.redirected) {
          const copy = res.clone();
          caches.open(PAGES).then((c) => c.put(url.pathname, copy));
        }
        return res;
      }).catch(() =>
        caches.open(PAGES).then((c) => c.match(url.pathname)).then((hit) => hit || caches.match("/offline.html"))
      )
    );
  }
});
