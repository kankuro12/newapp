/** Manual section for each workspace screen; the first matching prefix wins. */
const sections: [prefix: string, section: string][] = [
  ['/gym', 'gym'],
  ['/pos/full', 'full-pos'],
  ['/pos/setup', 'pos'],
  ['/pos', 'pos'],
  ['/workflows', 'workflows'],
  ['/workflow', 'workflows'],
  ['/fulfilment', 'workflows'],
  ['/document/', 'corrections'],
  ['/documents', 'bills'],
  ['/sales', 'bills'],
  ['/purchases', 'bills'],
  ['/expenses', 'bills'],
  ['/collections', 'payments'],
  ['/followup', 'payments'],
  ['/money', 'payments'],
  ['/regular', 'regular'],
  ['/contacts', 'parties'],
  ['/items', 'items'],
  ['/item-categories', 'items'],
  ['/imports', 'items'],
  ['/barcodes', 'items'],
  ['/labels', 'items'],
  ['/reorders', 'stock'],
  ['/stock', 'stock'],
  ['/price-lists', 'offers'],
  ['/basket-offers', 'offers'],
  ['/reports', 'records'],
  ['/audit', 'records'],
  ['/settings', 'branches'],
  ['/opening', 'start'],
];

export function manualSection(suffix: string): string {
  return sections.find(([prefix]) => suffix.startsWith(prefix))?.[1] || 'contents';
}

export const manualUrl = (section = 'contents') => `/help/user-manual.html#${section}`;
