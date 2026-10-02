/// <reference types="vitest" />

import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [
    react(),
    {
      name: 'asset-only-pwa',
      generateBundle(_, bundle) {
        const assets = ['/index.html', '/manifest.webmanifest', '/icons/icon-192.png', '/icons/icon-512.png', ...Object.keys(bundle).filter(name => /\.(js|css)$/.test(name)).map(name => '/' + name)]
        const version = 'business-book-assets-' + Date.now()
        this.emitFile({ type: 'asset', fileName: 'sw.js', source: `const CACHE=${JSON.stringify(version)};const ASSETS=${JSON.stringify(assets)};
self.addEventListener('install',event=>event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(ASSETS))));
self.addEventListener('activate',event=>event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key.startsWith('business-book-assets-')&&key!==CACHE).map(key=>caches.delete(key)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',event=>{const url=new URL(event.request.url);if(event.request.method!=='GET'||url.origin!==self.location.origin)return;if(ASSETS.includes(url.pathname)){event.respondWith(caches.open(CACHE).then(cache=>cache.match(url.pathname)).then(hit=>hit||fetch(event.request)));return;}if(event.request.mode==='navigate'&&!/^\\/(api|platform-auth|sanctum|login|logout|register|forgot-password|reset-password|email|user)(\\/|$)/.test(url.pathname)){event.respondWith(fetch(event.request).catch(()=>caches.open(CACHE).then(cache=>cache.match('/index.html'))));}});` })
      },
    }
  ],
  server: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
    proxy: Object.fromEntries(
      ['/api', '/up', '/sanctum', '/login', '/logout', '/register', '/forgot-password', '/reset-password', '/email', '/user', '/platform-auth']
        .map(path => [path, { target: 'http://127.0.0.1:8000' }])
    ),
  },
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: './src/setupTests.ts',
  }
})
