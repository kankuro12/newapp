import { expect, it } from 'vitest';
import { pwaRequest } from './pwa';
it('never intercepts financial APIs or auth even if accidentally listed as assets', () => {
  for (const path of [
    '/api/me',
    '/api/app/shop/reports',
    '/platform-auth/login',
    '/sanctum/csrf-cookie',
    '/login',
    '/logout',
    '/user/profile-information',
    '/email/verify/1',
  ]) {
    expect(
      pwaRequest(new URL('https://book.test' + path), 'GET', 'navigate', 'https://book.test', [
        path,
      ]),
    ).toBeNull();
  }
});
it('only caches listed static assets and falls back to shell for same-origin navigation', () => {
  expect(
    pwaRequest(new URL('https://book.test/assets/app.js'), 'GET', 'cors', 'https://book.test', [
      '/assets/app.js',
    ]),
  ).toBe('asset');
  expect(
    pwaRequest(new URL('https://book.test/app/shop'), 'GET', 'navigate', 'https://book.test', []),
  ).toBe('navigation');
  expect(
    pwaRequest(new URL('https://book.test/private.pdf'), 'GET', 'cors', 'https://book.test', []),
  ).toBeNull();
  expect(
    pwaRequest(new URL('https://other.test/assets/app.js'), 'GET', 'cors', 'https://book.test', [
      '/assets/app.js',
    ]),
  ).toBeNull();
  expect(
    pwaRequest(new URL('https://book.test/assets/app.js'), 'POST', 'cors', 'https://book.test', [
      '/assets/app.js',
    ]),
  ).toBeNull();
});
