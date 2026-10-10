import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { PartyTrading } from './PartyTools';
import { Context } from '../lib/context';
import type { Business } from '../lib/types';

const state = vi.hoisted(() => ({
  loading: false,
  trading: {
    data: {
      id: '3',
      name: 'Customer',
      is_customer: true,
      is_supplier: true,
      trading_version: 1,
      sales_terms_days: 0,
      purchase_terms_days: 0,
    },
  },
  save: vi.fn(),
}));
vi.mock('../lib/api', () => ({
  ApiError: class extends Error {},
  useOnline: () => true,
  useSave: () => ({ busy: false, uncertain: false, save: state.save, clear: vi.fn() }),
  useData: (url: string | null) =>
    url?.endsWith('/trading')
      ? { loading: state.loading, data: state.loading ? undefined : state.trading, reload: vi.fn() }
      : {
          loading: false,
          data: {
            data: url?.includes('/price-lists?') ? [{ id: '7', name: 'Wholesale' }] : [],
            last_page: 1,
          },
          reload: vi.fn(),
        },
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

it('keeps price assignment and payment drafts mounted during refresh with original versions', () => {
  function page(revision: number) {
    return (
      <MemoryRouter initialEntries={['/app/shop/contacts/3/trading']}>
        <Context.Provider
          value={{
            base: '/api/app/shop',
            path: '/app/shop',
            business: { id: '1', role: 'owner' } as Business,
            today: 20830103,
            revision,
            changed: () => {},
            t: (s) => s,
            locale: 'en',
          }}
        >
          <Routes>
            <Route path="/app/shop/contacts/:id/trading" element={<PartyTrading />} />
          </Routes>
        </Context.Provider>
      </MemoryRouter>
    );
  }
  const view = render(page(0));
  fireEvent.change(screen.getByLabelText('Price for'), { target: { value: 'purchase' } });
  fireEvent.change(screen.getByLabelText('Customer credit limit (NPR)'), {
    target: { value: '50' },
  });
  fireEvent.change(screen.getByLabelText('Customer price list'), { target: { value: '7' } });
  state.loading = true;
  view.rerender(page(1));
  expect(screen.getByLabelText('Customer credit limit (NPR)')).toHaveValue('50');
  expect(screen.getByLabelText('Customer price list')).toHaveValue('7');
  expect(screen.getByLabelText('Price for')).toHaveValue('purchase');
  expect(screen.getByRole('button', { name: 'Save list assignment' })).toBeDisabled();
  state.loading = false;
  state.trading = { data: { ...state.trading.data, trading_version: 2 } };
  view.rerender(page(2));
  fireEvent.click(screen.getByRole('button', { name: 'Save list assignment' }));
  expect(state.save.mock.calls[0][1]).toMatchObject({ version: 1, sales_price_list_id: '7' });
  fireEvent.click(screen.getByRole('button', { name: 'Save payment terms' }));
  expect(state.save.mock.calls[1][1]).toMatchObject({ version: 1, credit_limit: '50' });
});
