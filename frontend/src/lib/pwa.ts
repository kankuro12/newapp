export function pwaRequest(
  url: URL,
  method: string,
  mode: string,
  origin: string,
  assets: string[],
): 'asset' | 'navigation' | null {
  if (
    method !== 'GET' ||
    url.origin !== origin ||
    /^\/(api|platform-auth|sanctum|login|logout|register|forgot-password|reset-password|email|user)(\/|$)/.test(
      url.pathname,
    )
  )
    return null;
  if (assets.includes(url.pathname)) return 'asset';
  return mode === 'navigate' ? 'navigation' : null;
}
