import { act, renderHook } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { resetSession, useSave } from './api';

afterEach(() => {
  vi.unstubAllGlobals();
  resetSession();
});
it('keeps original retry identity after lost reply and changed access', async () => {
  const bodies: Record<string, unknown>[] = [];
  let attempts = 0;
  vi.stubGlobal('crypto', { randomUUID: () => 'test-retry-identity' });
  vi.stubGlobal(
    'fetch',
    vi.fn(async (path: string, options?: RequestInit) => {
      if (path === '/sanctum/csrf-cookie') return new Response(null, { status: 204 });
      bodies.push(JSON.parse(options?.body as string));
      attempts++;
      if (attempts === 1) throw new Error('Reply lost after posting');
      if (attempts === 2)
        return new Response(JSON.stringify({ message: 'Access expired' }), { status: 403 });
      return new Response(JSON.stringify({ data: { id: 'original-entry' } }), { status: 200 });
    }),
  );
  const { result } = renderHook(useSave);
  const success = vi.fn();
  await act(() => result.current.save('/api/payment', { amount: '10' }, success));
  await act(() => result.current.save('/api/payment', { amount: '20' }, success));
  expect(bodies).toHaveLength(1);
  await act(() => result.current.save('/api/payment', { amount: '10' }, success));
  await act(() => result.current.save('/api/payment', { amount: '20' }, success));
  expect(bodies).toHaveLength(2);
  await act(() => result.current.save('/api/payment', { amount: '10' }, success));
  expect(bodies.map((body) => body.mutation_uuid)).toEqual([
    'test-retry-identity',
    'test-retry-identity',
    'test-retry-identity',
  ]);
  expect(success).toHaveBeenCalledWith({ id: 'original-entry' });
});
it('retries original live action after server advances its version', async () => {
  const bodies: Record<string, unknown>[] = [];
  let attempts = 0;
  vi.stubGlobal('crypto', { randomUUID: () => 'live-original' });
  vi.stubGlobal(
    'fetch',
    vi.fn(async (path: string, options?: RequestInit) => {
      if (path === '/sanctum/csrf-cookie') return new Response(null, { status: 204 });
      bodies.push(JSON.parse(options?.body as string));
      if (++attempts === 1) throw new Error('Reply lost');
      return new Response(JSON.stringify({ data: { id: 'ticket' } }));
    }),
  );
  const { result } = renderHook(useSave);
  const done = vi.fn();
  await act(() => result.current.save('/api/order/send', { version: 1 }, done));
  await act(() => result.current.save('/api/order/send', { version: 2 }, done));
  expect(bodies).toHaveLength(1);
  await act(() => result.current.retry());
  expect(bodies).toHaveLength(2);
  expect(bodies[0]).toEqual(bodies[1]);
  expect(done).toHaveBeenCalledWith({ id: 'ticket' });
});
