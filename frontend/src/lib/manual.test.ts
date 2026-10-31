import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, it } from 'vitest';
import { manualSection } from './manual';

// Vitest runs from the frontend folder, where the manual is served from public/.
const manual = readFileSync(resolve(process.cwd(), 'public/help/user-manual.html'), 'utf8');
const chapterExists = (id: string) => manual.includes(`id="${id}"`);

it('header Help sections all exist in the manual', () => {
  const suffixes = [
    '',
    '/gym',
    '/pos/full',
    '/pos/setup',
    '/pos',
    '/workflows',
    '/workflow/4',
    '/fulfilment/4',
    '/document/12',
    '/document/12/return',
    '/documents/sale/new',
    '/sales',
    '/purchases',
    '/collections',
    '/followups',
    '/money',
    '/regular',
    '/contacts',
    '/items',
    '/item-categories',
    '/imports',
    '/barcodes',
    '/labels',
    '/price-lists',
    '/basket-offers',
    '/reorders',
    '/stock/count',
    '/reports',
    '/audit',
    '/settings',
    '/opening',
    '/more',
  ];
  for (const suffix of suffixes) {
    expect(chapterExists(manualSection(suffix)), suffix).toBe(true);
  }
});
