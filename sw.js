// Service Worker para o Agenda PRO
const CACHE_NAME = "agenda-pro-cache-v1";
const urlsToCache = [
  "/",
  "/index.html",
  "/manifest.json",
  "/favicon.ico",
  "/og-image.png",
];

// Instalação do Service Worker e cache de arquivos estáticos
self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(urlsToCache);
    })
  );
});

// Estratégia de cache: Stale-While-Revalidate para recursos estáticos
self.addEventListener("fetch", (event) => {
  // Pular requisições de terceiros
  if (
    !event.request.url.startsWith(self.location.origin) &&
    !event.request.url.includes("supabase")
  ) {
    return;
  }

  // Não cachear requisições POST
  if (event.request.method !== "GET") {
    return;
  }

  event.respondWith(
    caches.match(event.request).then((response) => {
      // Estratégia stale-while-revalidate
      const fetchPromise = fetch(event.request)
        .then((networkResponse) => {
          // Verificar se a resposta é válida
          if (
            networkResponse &&
            networkResponse.status === 200 &&
            networkResponse.type === "basic"
          ) {
            const responseToCache = networkResponse.clone();
            caches.open(CACHE_NAME).then((cache) => {
              cache.put(event.request, responseToCache);
            });
          }
          return networkResponse;
        })
        .catch(() => {
          // Se a rede falhar, temos o cache como fallback
          return response;
        });

      return response || fetchPromise;
    })
  );
});

// Limpeza de caches antigos quando uma nova versão é ativada
self.addEventListener("activate", (event) => {
  const cacheWhitelist = [CACHE_NAME];

  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheWhitelist.indexOf(cacheName) === -1) {
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
});
