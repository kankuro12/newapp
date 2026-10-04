import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';

const container = document.getElementById('root');
const root = createRoot(container!);
root.render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
);

if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => { void navigator.serviceWorker.register('/sw.js').catch(() => undefined); });
}
window.addEventListener('beforeunload', event => {
  if (document.querySelector('[data-dirty="true"]')) { event.preventDefault(); }
});
document.addEventListener('click', event => {
  const link = (event.target as Element)?.closest('a[href]');
  if (link?.hasAttribute('download') && new URL(link.getAttribute('href')!, location.href).origin === location.origin) return;
  if (link && document.querySelector('[data-dirty="true"]') && new URL(link.getAttribute('href')!, location.href).pathname !== location.pathname && !window.confirm('Leave unsaved changes?')) { event.preventDefault(); event.stopPropagation(); }
}, true);
