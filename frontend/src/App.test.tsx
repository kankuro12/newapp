import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { vi } from 'vitest';
import App from './App';

test('anonymous visitor receives sign-in fields after session check', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ message: 'Unauthenticated.' }), { status: 401 })));
  render(<App />);
  expect(await screen.findByRole('heading', { name: 'Your business, in good order.' })).toBeDefined();
  expect(screen.getByLabelText('Email')).toBeDefined();
  expect(screen.getByLabelText('Password')).toBeDefined();
  vi.unstubAllGlobals();
});

test('unavailable session shows retry without inviting duplicate sign-in', async () => {
  const fetch = vi.fn().mockRejectedValueOnce(new Error('offline')).mockResolvedValue(new Response(JSON.stringify({ message: 'Unauthenticated.' }), { status: 401 }));
  vi.stubGlobal('fetch', fetch);
  render(<App />);
  expect(await screen.findByRole('alert')).toHaveTextContent('Connection unavailable');
  expect(screen.queryByLabelText('Password')).toBeNull();
  fireEvent.click(screen.getByText('Retry'));
  expect(await screen.findByLabelText('Password')).toBeDefined();
  expect(fetch).toHaveBeenCalledTimes(2);
  vi.unstubAllGlobals();
});

test('platform session also preserves connection error and retry', async () => {
  window.history.replaceState({}, '', '/platform');
  vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
  render(<App />);
  expect(await screen.findByRole('alert')).toHaveTextContent('Connection unavailable');
  expect(screen.getByText('Retry')).toBeDefined();
  expect(screen.queryByLabelText('Password')).toBeNull();
  vi.unstubAllGlobals();
  window.history.replaceState({}, '', '/');
});
