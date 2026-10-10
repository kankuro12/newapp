import { useEffect, useRef, useState } from 'react';

export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
    public errors: Record<string, string[]> = {},
  ) {
    super(message);
  }
}
let csrf: Promise<void> | undefined;
export function resetSession() {
  csrf = undefined;
}
export async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const method = options.method || 'GET';
  const headers = new Headers(options.headers);
  headers.set('Accept', 'application/json');
  if (!['GET', 'HEAD'].includes(method)) {
    csrf ??= fetch('/sanctum/csrf-cookie', { credentials: 'include', cache: 'no-store' })
      .then((response) => {
        if (!response.ok) throw new ApiError(response.status, 'Could not start secure session.');
      })
      .catch((error) => {
        csrf = undefined;
        throw error;
      });
    await csrf;
    const cookie = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));
    if (cookie) headers.set('X-XSRF-TOKEN', decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)));
  }
  if (options.body && !(options.body instanceof FormData))
    headers.set('Content-Type', 'application/json');
  const response = await fetch(path, {
    ...options,
    method,
    headers,
    credentials: 'include',
    cache: 'no-store',
  }).catch(() => {
    throw new Error('Connection unavailable. Reconnect, then retry original entry.');
  });
  const data = response.status === 204 ? undefined : await response.json().catch(() => undefined);
  if (!response.ok) {
    if (response.status === 419) resetSession();
    throw new ApiError(
      response.status,
      data?.message || 'Could not confirm save. Retry safely.',
      data?.errors,
    );
  }
  if (response.status !== 204 && data === undefined)
    throw new ApiError(502, 'Save response unavailable. Retry original entry safely.');
  return data as T;
}
export const send = <T>(path: string, input: unknown, method = 'POST') =>
  request<T>(path, { method, body: JSON.stringify(input) });
export function useOnline() {
  const [online, setOnline] = useState(navigator.onLine);
  useEffect(() => {
    const update = () => setOnline(navigator.onLine);
    window.addEventListener('online', update);
    window.addEventListener('offline', update);
    return () => {
      window.removeEventListener('online', update);
      window.removeEventListener('offline', update);
    };
  }, []);
  return online;
}
export function useData<T>(path: string | null, revision = 0) {
  const [data, setData] = useState<T>();
  const [error, setError] = useState<Error>();
  const [loading, setLoading] = useState(true);
  const [reloadCount, setReload] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    setData(undefined);
    setError(undefined);
    if (!path) {
      setLoading(false);
      return () => controller.abort();
    }
    setLoading(true);
    request<T>(path, { signal: controller.signal })
      .then((value) => {
        if (!controller.signal.aborted) setData(value);
      })
      .catch((error) => {
        if (!controller.signal.aborted) setError(error);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [path, revision, reloadCount]);
  return { data, error, loading, reload: () => setReload((count) => count + 1) };
}

// ponytail: foreground HTTP refresh every 2s; replace with push events when measured restaurant load requires it.
export function useLiveData<T>(path: string, revision = 0) {
  const [data, setData] = useState<T>();
  const [error, setError] = useState<Error>();
  const [updatedAt, setUpdatedAt] = useState<number>();
  useEffect(() => {
    const controller = new AbortController();
    let timer: ReturnType<typeof setTimeout>;
    setData(undefined);
    setError(undefined);
    setUpdatedAt(undefined);
    async function refresh() {
      if (document.visibilityState !== 'hidden') {
        try {
          const value = await request<T>(path, { signal: controller.signal });
          if (!controller.signal.aborted) {
            setData(value);
            setError(undefined);
            setUpdatedAt(Date.now());
          }
        } catch (cause) {
          if (!controller.signal.aborted) {
            setError(cause as Error);
          }
        }
      }
      if (!controller.signal.aborted) {
        timer = setTimeout(refresh, 2000);
      }
    }
    void refresh();
    return () => {
      controller.abort();
      clearTimeout(timer);
    };
  }, [path, revision]);
  return { data, error, updatedAt };
}

export function useSave() {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<Error>();
  const [uncertain, setUncertain] = useState(false);
  const pending = useRef<{ signature: string; uuid: string; uncertain?: boolean } | undefined>(
    undefined,
  );
  const original = useRef<(() => Promise<void>) | undefined>(undefined);
  async function save<T>(
    path: string,
    input: Record<string, unknown>,
    success: (data: T) => void,
    method = 'POST',
  ) {
    if (busy) return;
    const signature = path + JSON.stringify(input);
    if (pending.current && pending.current.signature !== signature) {
      setError(
        new Error('Previous save unconfirmed. Restore original values and retry before editing.'),
      );
      return;
    }
    pending.current ??= { signature, uuid: crypto.randomUUID() };
    original.current = () => save(path, input, success, method);
    setBusy(true);
    setError(undefined);
    try {
      const response = await send<{ data: T }>(
        path,
        { ...input, mutation_uuid: pending.current.uuid },
        method,
      );
      pending.current = undefined;
      original.current = undefined;
      setUncertain(false);
      success(response?.data);
    } catch (cause) {
      const error =
        cause instanceof Error ? cause : new Error('Could not confirm save. Retry safely.');
      if (
        error instanceof ApiError &&
        error.status >= 400 &&
        error.status < 500 &&
        !pending.current?.uncertain
      ) {
        pending.current = undefined;
        original.current = undefined;
        setUncertain(false);
        const first = Object.keys(error.errors)[0];
        if (first) (document.getElementsByName(first)[0] as HTMLElement)?.focus();
      } else if (pending.current) {
        pending.current.uncertain = true;
        setUncertain(true);
      }
      setError(error);
    } finally {
      setBusy(false);
    }
  }
  return {
    busy,
    error,
    uncertain,
    save,
    retry: () => original.current?.() ?? Promise.resolve(),
    clear: () => {
      if (pending.current?.uncertain) return;
      setError(undefined);
      pending.current = undefined;
      original.current = undefined;
    },
  };
}
