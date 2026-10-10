import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import Imports from './ImportTools';
import { Context } from '../lib/context';
import type { Business } from '../lib/types';

const saved = vi.hoisted(() => vi.fn());
const preview = vi.hoisted(() => ({
  resource: 'items',
  headers: ['name', 'sku', 'sale_price'],
  mapping: ['name', 'sku', 'sale_price'],
  columns: ['id', 'name', 'sku', 'sale_price'],
  headerErrors: [],
  ignored: [],
  categories: [],
  counts: { create: 1, update: 0, categories: 0 },
  valid: true,
  digest: 'a'.repeat(64),
  version: '17',
  rows: [
    {
      row: 2,
      id: null,
      name: 'Juice',
      action: 'create',
      category: null,
      changes: [{ column: 'sale_price', before: null, after: '80.25' }],
      errors: [],
    },
  ],
}));
const sent = vi.hoisted(() => vi.fn(async () => ({ data: preview })));
vi.mock('../lib/api', () => ({
  ApiError: class extends Error {},
  send: sent,
  useData: () => ({ loading: false, data: { data: [], last_page: 1 } }),
  useSave: () => ({ busy: false, uncertain: false, save: saved, clear: () => {} }),
  useOnline: () => true,
}));
vi.mock('@ionic/react', () => ({
  IonButton: ({
    children,
    type = 'button',
    ...props
  }: React.ButtonHTMLAttributes<HTMLButtonElement>) => (
    <button type={type} {...props}>
      {children}
    </button>
  ),
  IonIcon: () => null,
  IonSpinner: () => null,
}));

it('requires a fresh preview after source or mapping edits and applies exactly reviewed CSV', async () => {
  Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: () => ({ matches: true, addEventListener: () => {}, removeEventListener: () => {} }),
  });
  render(
    <MemoryRouter initialEntries={['/?resource=items']}>
      <Context.Provider
        value={{
          base: '/api/app/shop',
          path: '/app/shop',
          business: { role: 'owner' } as Business,
          today: 20830103,
          revision: 0,
          changed: () => {},
          t: (s) => s,
          locale: 'en',
        }}
      >
        <Imports />
      </Context.Provider>
    </MemoryRouter>,
  );
  const csv = 'name,sku,sale_price\nJuice,JUICE,80.25\n';
  fireEvent.change(screen.getByLabelText('CSV text'), { target: { value: csv } });
  expect(screen.queryByRole('button', { name: 'Apply reviewed import' })).toBeNull();
  fireEvent.click(screen.getByRole('button', { name: 'Preview CSV' }));
  await waitFor(() =>
    expect(screen.getByRole('button', { name: 'Continue to Columns & errors' })).toBeEnabled(),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Columns & errors' }));
  fireEvent.change(screen.getByLabelText('Column: sale_price'), { target: { value: '' } });
  expect(screen.getByRole('button', { name: 'Apply reviewed import' })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: '1 CSV source' }));
  const corrected = csv.replace('80.25', '90.35');
  fireEvent.change(screen.getByLabelText('CSV text'), { target: { value: corrected } });
  expect(screen.queryByRole('button', { name: 'Apply reviewed import' })).toBeNull();
  fireEvent.click(screen.getByRole('button', { name: 'Preview CSV' }));
  await waitFor(() => expect(sent).toHaveBeenCalledTimes(2));
  await waitFor(() =>
    expect(screen.getByRole('button', { name: 'Continue to Columns & errors' })).toBeEnabled(),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Columns & errors' }));
  await waitFor(() =>
    expect(screen.getByRole('button', { name: 'Continue to Review & apply' })).toBeEnabled(),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Review & apply' }));
  fireEvent.click(screen.getByRole('button', { name: 'Apply reviewed import' }));
  expect(saved).toHaveBeenCalledOnce();
  expect(saved.mock.calls[0][0]).toBe('/api/app/shop/imports');
  expect(saved.mock.calls[0][1]).toEqual({
    resource: 'items',
    csv: corrected,
    mapping: ['name', 'sku', ''],
    digest: preview.digest,
    version: '17',
  });
});

it('price-list replacement requires fresh review and applies the selected mode', async () => {
  sent.mockClear();
  saved.mockClear();
  Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: () => ({ matches: true, addEventListener: () => {}, removeEventListener: () => {} }),
  });
  render(
    <MemoryRouter initialEntries={['/?resource=price_lists']}>
      <Context.Provider
        value={{
          base: '/api/app/shop',
          path: '/app/shop',
          business: { role: 'owner' } as Business,
          today: 20830103,
          revision: 0,
          changed: () => {},
          t: (s) => s,
          locale: 'en',
        }}
      >
        <Imports />
      </Context.Provider>
    </MemoryRouter>,
  );
  expect(screen.getByLabelText('Import type')).toHaveValue('price_lists');
  const csv = 'name,channel,item_sku,min_qty,price\nWholesale,sale,00012,0,100.25\n';
  fireEvent.change(screen.getByLabelText('CSV text'), { target: { value: csv } });
  fireEvent.click(screen.getByRole('button', { name: 'Preview CSV' }));
  await waitFor(() =>
    expect(screen.getByRole('button', { name: 'Continue to Columns & errors' })).toBeEnabled(),
  );
  expect(screen.getByRole('button', { name: 'Apply reviewed import' })).toBeEnabled();
  fireEvent.click(screen.getByLabelText('Replace tiers for included lists'));
  expect(screen.getByRole('button', { name: 'Apply reviewed import' })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Preview CSV' }));
  await waitFor(() => expect(sent).toHaveBeenCalledTimes(2));
  await waitFor(() =>
    expect(screen.getByRole('button', { name: 'Apply reviewed import' })).toBeEnabled(),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Columns & errors' }));
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Review & apply' }));
  fireEvent.click(screen.getByRole('button', { name: 'Apply reviewed import' }));
  expect(saved.mock.calls[0][1]).toEqual({
    resource: 'price_lists',
    csv,
    replace_rules: true,
    digest: preview.digest,
    version: '17',
  });
  expect(screen.getByRole('link', { name: 'Price lists' })).toHaveAttribute(
    'href',
    '/app/shop/price-lists',
  );
});
