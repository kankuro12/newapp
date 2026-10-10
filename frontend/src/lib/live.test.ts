import { act, renderHook } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { useLiveData } from './api';
afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
});
it('refreshes operations without writes, keeps last successful data on failure and drops it on branch switch', async () => {
  vi.useFakeTimers();
  let calls = 0;
  const methods: string[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (_path: string, options: RequestInit) => {
      methods.push(options.method || 'GET');
      calls++;
      if (calls === 2) throw new Error('Offline');
      return new Response(
        JSON.stringify({ data: [{ id: calls === 1 ? 'first-branch' : 'second-branch' }] }),
      );
    }),
  );
  const { result, rerender, unmount } = renderHook(
    ({ path }) => useLiveData<{ data: { id: string }[] }>(path),
    { initialProps: { path: '/api/branch-one/orders' } },
  );
  await act(async () => {});
  expect(result.current.data?.data[0].id).toBe('first-branch');
  await act(async () => {
    await vi.advanceTimersByTimeAsync(2000);
  });
  expect(result.current.error).toBeDefined();
  expect(result.current.data?.data[0].id).toBe('first-branch');
  await act(async () => rerender({ path: '/api/branch-two/orders' }));
  expect(result.current.data?.data[0].id).toBe('second-branch');
  expect(methods).toEqual(['GET', 'GET', 'GET']);
  unmount();
  await act(async () => {
    await vi.advanceTimersByTimeAsync(4000);
  });
  expect(methods).toHaveLength(3);
});
