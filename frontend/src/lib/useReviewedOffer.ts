import { useEffect, useState } from 'react';
import { request } from './api';
import type { OfferProof } from './types';
export interface OfferPreview {
  fingerprint: string; basket_offer: OfferProof | null;
  total_paisa: string; tax_paisa: string; subtotal_paisa: string; invoice_discount_paisa: string; line_discount_paisa: string;
  lines: { gross_paisa: string; line_discount_paisa: string; net_base_paisa: string; invoice_discount_paisa: string; tax_paisa: string; total_paisa: string }[];
}

export function useReviewedOffer(endpoint: string, input: object, enabled: boolean, locked: boolean) {
  const signature = JSON.stringify(input);
  const [refresh, setRefresh] = useState(0);
  const key = JSON.stringify([endpoint, signature, refresh]);
  const [result, setResult] = useState<{ key: string; preview?: OfferPreview; error?: Error }>();
  useEffect(() => {
    if (!enabled || locked) return;
    const controller = new AbortController();
    const timer = setTimeout(() => {
      void request<{ data: OfferPreview }>(endpoint, { method: 'POST', body: signature, signal: controller.signal }).then(value => {
        if (!controller.signal.aborted) setResult({ key, preview: value.data });
      }).catch(error => { if (!controller.signal.aborted) setResult({ key, error: error as Error }); });
    }, 200);
    return () => { clearTimeout(timer); controller.abort(); };
  }, [endpoint, signature, key, enabled, locked]);
  const current = enabled && result?.key === key ? result : undefined;
  return { preview: current?.preview, error: current?.error, pending: enabled && !current, recheck: () => setRefresh(n => n + 1) };
}

