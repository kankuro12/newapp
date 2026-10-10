import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { Reorders } from './CatalogTools';
import { Context } from '../lib/context';
import type { Business } from '../lib/types';

const saved = vi.hoisted(() => vi.fn());
vi.mock('../lib/api', () => ({
  ApiError: class extends Error {},
  useOnline: () => true,
  useSave: () => ({ busy: false, uncertain: false, save: saved }),
  useData: () => ({
    loading: false,
    reload: () => {},
    data: {
      last_page: 1,
      data: [
        {
          id: '8',
          name: 'Juice',
          unit_label: 'bottle',
          preferred_supplier_id: '3',
          supplier_name: 'Wholesale',
          supplier_active: true,
          qty_milli: '3000',
          low_stock_qty_milli: '4000',
          reorder_target_qty_milli: '10000',
          pending_qty_milli: '2000',
          suggested_qty_milli: '5000',
          unit_price_paisa: '8025',
          default_tax_category: 'outside_scope',
          default_tax_bps: '0',
          fingerprint: 'reviewed-state',
        },
        {
          id: '9',
          name: 'Covered item',
          suggested_qty_milli: '0',
          supplier_active: true,
          qty_milli: '0',
          low_stock_qty_milli: '1000',
          reorder_target_qty_milli: '1000',
          pending_qty_milli: '1000',
        },
      ],
    },
  }),
}));
vi.mock('../components/Picker', () => ({
  default: ({
    kind,
    onPick,
  }: {
    kind: string;
    onPick: (row: { id: string; name: string }) => void;
  }) =>
    kind === 'contacts' ? (
      <button type="button" onClick={() => onPick({ id: '3', name: 'Wholesale' })}>
        Pick Wholesale
      </button>
    ) : null,
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

it('reviews editable exact quantities and prices through mobile steps before saving a purchase order', async () => {
  Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: () => ({ matches: true, addEventListener: () => {}, removeEventListener: () => {} }),
  });
  render(
    <MemoryRouter>
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
        <Reorders />
      </Context.Provider>
    </MemoryRouter>,
  );
  expect(screen.getByRole('button', { name: 'Continue to Items' })).toBeDisabled();
  expect(screen.getByRole('checkbox', { name: 'Juice' })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Pick Wholesale' }));
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Items' }));
  expect(screen.getByRole('checkbox', { name: 'Covered item' })).toBeDisabled();
  fireEvent.click(screen.getByRole('checkbox', { name: 'Juice' }));
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Review & save' }));
  fireEvent.change(screen.getByLabelText('Order quantity'), { target: { value: '2.125' } });
  fireEvent.change(screen.getByLabelText('Purchase price (NPR)'), { target: { value: '80.25' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save purchase order' }));
  await waitFor(() => expect(saved).toHaveBeenCalledOnce());
  const [url, input] = saved.mock.calls[0];
  expect(url).toBe('/api/app/shop/workflows');
  expect(input).toMatchObject({
    kind: 'purchase_order',
    contact_id: '3',
    business_date_bs: '2083-01-03',
    expected_total_paisa: '17053',
    reorder: { 8: 'reviewed-state' },
    lines: [{ item_id: '8', qty: '2.125', unit_price: '80.25' }],
  });
  expect(input.paid_now).toBeUndefined();
  expect(input.money_account_id).toBeUndefined();
});
