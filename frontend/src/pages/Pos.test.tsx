import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import Pos from './Pos';
import { Context } from '../lib/context';
import type { Business } from '../lib/types';

const state = vi.hoisted(() => ({
  save: vi.fn(),
  request: vi.fn(async (_url: string, _options?: RequestInit) => {
    void _url;
    void _options;
    return {
      data: {
        total_paisa: '10000',
        fingerprint: 'standard-proof',
        lines: [{ item_id: '5', qty_milli: '1000', total_paisa: '10000' }],
      },
    };
  }),
}));
vi.mock('../lib/api', () => ({
  ApiError: class extends Error {},
  request: state.request,
  useOnline: () => true,
  useLiveData: () => ({}),
  useSave: () => ({ busy: false, uncertain: false, save: state.save }),
  useData: (url: string) => ({
    loading: false,
    data: url.endsWith('/pos/config')
      ? { data: { units: { unit: ['count', '1', '1'] }, methods: ['quantity'], resources: [] } }
      : url.endsWith('/lookup')
        ? { data: { accounts: [{ id: '1', name: 'Cash' }] } }
        : { data: [], last_page: 1 },
    reload: vi.fn(),
  }),
}));
vi.mock('./BarcodeTools', () => ({
  ScanItem: ({ onScan }: { onScan: (item: object, measurement: object) => void }) => (
    <button
      onClick={() =>
        onScan(
          { id: '5', name: 'Work', unit_label: 'job', pos_unit: 'unit', sale_price_paisa: '10000' },
          { mode: 'quantity', value: '1', unit: 'unit' },
        )
      }
    >
      Scan Work
    </button>
  ),
}));
vi.mock('./CatalogTools', () => ({ CategoryFilter: () => null }));
vi.mock('./PriceLists', () => ({
  PriceListSelect: ({ value, onChange }: { value: string; onChange: (value: string) => void }) => (
    <label>
      Price list
      <select value={value} onChange={(e) => onChange(e.target.value)}>
        <option value="">Standard</option>
        <option value="7">Wholesale</option>
      </select>
    </label>
  ),
}));
vi.mock('../components/Picker', () => ({ default: () => null }));
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

it('invalidates old POS total immediately when list changes and posts current context', async () => {
  render(
    <MemoryRouter>
      <Context.Provider
        value={{
          base: '/api/app/shop',
          path: '/app/shop',
          business: { id: '1', name: 'Shop', role: 'owner', pos_profile: 'general' } as Business,
          today: 20830103,
          revision: 0,
          changed: () => {},
          t: (s) => s,
          locale: 'en',
        }}
      >
        <Pos />
      </Context.Provider>
    </MemoryRouter>,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Scan Work' }));
  await waitFor(() =>
    expect(screen.getByRole('button', { name: 'Save bill + payment' })).toBeEnabled(),
  );
  let release: (value: Awaited<ReturnType<typeof state.request>>) => void = () => {};
  state.request.mockImplementationOnce(
    () =>
      new Promise((resolve) => {
        release = resolve;
      }),
  );
  fireEvent.change(screen.getByLabelText('Price list'), { target: { value: '7' } });
  expect(screen.getByRole('button', { name: 'Save bill + payment' })).toBeDisabled();
  await waitFor(() => expect(state.request).toHaveBeenCalledTimes(2));
  const body = JSON.parse(state.request.mock.calls[1][1]!.body as string);
  expect(body.price_list_id).toBe('7');
  expect(body.business_date_bs).toBe(20830103);
  release({
    data: {
      total_paisa: '8000',
      fingerprint: 'list-proof',
      lines: [{ item_id: '5', qty_milli: '1000', total_paisa: '8000' }],
    },
  });
  await waitFor(() =>
    expect(screen.getByRole('button', { name: 'Save bill + payment' })).toBeEnabled(),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Save bill + payment' }));
  expect(state.save.mock.calls[0][1]).toMatchObject({
    price_list_id: '7',
    expected_total_paisa: '8000',
    expected_fingerprint: 'list-proof',
    business_date_bs: 20830103,
  });
});
