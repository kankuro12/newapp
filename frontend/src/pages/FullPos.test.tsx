import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { Context } from '../lib/context';
import type { Business } from '../lib/types';
import FullPos from './FullPos';

vi.mock('../lib/api', () => ({
  request: vi.fn(),
  useSave: () => ({ save: vi.fn(), busy: false, uncertain: false, retry: vi.fn() }),
  useData: (path: string | null) => ({
    loading: false,
    data: path?.endsWith('/pos/config')
      ? {
          data: {
            profiles: [],
            methods: ['quantity'],
            units: { unit: ['count', 'Unit', 'unit'] },
            resources: [],
          },
        }
      : { data: [], last_page: 1 },
  }),
  useOnline: () => true,
}));
vi.mock('./BarcodeTools', () => ({ ScanItem: () => null }));

function page(profile: string) {
  return (
    <MemoryRouter>
      <Context.Provider
        value={{
          business: { name: 'Shop', slug: 'shop', role: 'owner', pos_profile: profile } as Business,
          base: '/api/app/shop',
          path: '/app/shop',
          today: 20830103,
          revision: 0,
          changed: vi.fn(),
          t: (s) => s,
          locale: 'en',
        }}
      >
        <FullPos />
      </Context.Provider>
    </MemoryRouter>
  );
}

it.each([
  ['restaurant', 'Open tables'],
  ['salon', 'Appointments'],
  ['gym', 'Member check-in'],
  ['garage', 'Ready jobs'],
  ['fabrication', 'Ready jobs'],
])('adds the %s mode next to Sale', (profile, mode) => {
  render(page(profile));
  expect(screen.getByRole('button', { name: 'Sale' })).toHaveAttribute('aria-pressed', 'true');
  expect(screen.getByRole('button', { name: mode })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Quick POS' })).toHaveAttribute('href', '/app/shop/pos');
});

it('keeps a general shop to the sale screen with paging and an empty ticket', () => {
  render(page('general'));
  expect(
    screen.getAllByRole('button', { name: /Sale|tables|Appointments|check-in|jobs/ }),
  ).toHaveLength(1);
  expect(screen.getByText('Tap a product or scan a barcode to start.')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: /Pay/ })).toBeDisabled();
});
