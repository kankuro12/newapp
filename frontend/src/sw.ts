/// <reference lib="webworker" />
import { initializeApp } from '@firebase/app';
import { getMessaging, isSupported } from '@firebase/messaging/sw';
import { firebaseConfig, pushConfigured } from './lib/firebaseConfig';
import { pwaRequest } from './lib/pwa';
const worker = globalThis as unknown as ServiceWorkerGlobalScope;
const cacheName = '__BB_PWA_VERSION__';
const assets = '__BB_PWA_ASSETS__'.split('|');
worker.addEventListener('install', (event) =>
  event.waitUntil(
    caches
      .open(cacheName)
      .then((cache) => cache.addAll(assets))
      .then(() => worker.skipWaiting()),
  ),
);
worker.addEventListener('activate', (event) =>
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter((key) => key.startsWith('business-book-assets-') && key !== cacheName)
            .map((key) => caches.delete(key)),
        ),
      )
      .then(() => worker.clients.claim()),
  ),
);
worker.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  const policy = pwaRequest(
    url,
    event.request.method,
    event.request.mode,
    worker.location.origin,
    assets,
  );
  if (policy === 'asset') {
    event.respondWith(
      caches
        .open(cacheName)
        .then((cache) => cache.match(url.pathname))
        .then((hit) => hit || fetch(event.request)),
    );
    return;
  }
  if (policy === 'navigation') {
    event.respondWith(
      fetch(event.request).catch(() =>
        caches
          .open(cacheName)
          .then((cache) => cache.match('/index.html'))
          .then((hit) => hit || Response.error()),
      ),
    );
  }
});
if (pushConfigured())
  void isSupported()
    .then((supported) => {
      if (supported) getMessaging(initializeApp(firebaseConfig(), 'business-book-push'));
    })
    .catch(() => undefined);
