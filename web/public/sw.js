/*
 * Service worker de Mavi'oh — volontairement minimal.
 *
 * Ce qu'il garde : les fichiers de l'application (scripts et styles versionnés de /_next/static,
 * icônes) et la page « hors ligne ». Ce qu'il ne garde JAMAIS : les pages et les réponses de
 * l'API, qui contiennent des données personnelles et de santé. Rien de personnel ne reste donc
 * sur l'appareil après une déconnexion.
 */
const CACHE = "mavioh-v1";
const HORS_LIGNE = "/hors-ligne";

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches
      .open(CACHE)
      .then((cache) => cache.addAll([HORS_LIGNE, "/icons/icon-192.png", "/logoMoh.png"]))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((cles) => Promise.all(cles.filter((cle) => cle !== CACHE).map((cle) => caches.delete(cle))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener("fetch", (event) => {
  const { request } = event;
  if (request.method !== "GET") return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin || url.pathname.startsWith("/api/")) return;

  // Pages : toujours le réseau ; la page « hors ligne » seulement s'il ne répond pas.
  if (request.mode === "navigate") {
    event.respondWith(fetch(request).catch(() => caches.match(HORS_LIGNE)));
    return;
  }

  // Fichiers versionnés de l'application : immuables, servis depuis le cache une fois vus.
  if (url.pathname.startsWith("/_next/static/") || url.pathname.startsWith("/icons/")) {
    event.respondWith(
      caches.match(request).then(
        (enCache) =>
          enCache ||
          fetch(request).then((reponse) => {
            if (reponse.ok) {
              const copie = reponse.clone();
              caches.open(CACHE).then((cache) => cache.put(request, copie));
            }
            return reponse;
          }),
      ),
    );
  }
});
