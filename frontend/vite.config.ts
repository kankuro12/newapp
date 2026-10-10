/// <reference types="vitest" />

import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [
    react(),
    {
      name: 'asset-only-pwa',
      generateBundle(_, bundle) {
        this.emitFile({
          type: 'asset',
          fileName: 'third-party-notices.txt',
          source: readFileSync(new URL('../THIRD-PARTY-NOTICES.md', import.meta.url), 'utf8'),
        });
        const assets = [
          '/index.html',
          '/manifest.webmanifest',
          '/icons/icon-192.png',
          '/icons/icon-512.png',
          '/help/user-manual.html',
          '/help/manual/restaurant-checkout.jpg',
          '/help/manual/salon-checkout.jpg',
          ...Object.keys(bundle)
            .filter((name) => /\.(js|css)$/.test(name))
            .map((name) => '/' + name),
        ];
        const version = 'business-book-assets-' + Date.now();
        const worker = bundle['sw.js'];
        if (!worker || worker.type !== 'chunk') throw new Error('Bundled push worker missing');
        worker.code = worker.code
          .replace('__BB_PWA_VERSION__', version)
          .replace('__BB_PWA_ASSETS__', assets.join('|'));
      },
    },
  ],
  build: {
    rollupOptions: {
      input: {
        index: fileURLToPath(new URL('./index.html', import.meta.url)),
        sw: fileURLToPath(new URL('./src/sw.ts', import.meta.url)),
      },
      output: {
        entryFileNames: (chunk) => (chunk.name === 'sw' ? 'sw.js' : 'assets/[name]-[hash].js'),
      },
    },
  },
  server: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
    proxy: Object.fromEntries(
      [
        '/api',
        '/up',
        '/sanctum',
        '/login',
        '/logout',
        '/register',
        '/forgot-password',
        '/reset-password',
        '/email',
        '/user',
        '/platform-auth',
      ].map((path) => [path, { target: 'http://127.0.0.1:8000' }]),
    ),
  },
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: './src/setupTests.ts',
  },
});
