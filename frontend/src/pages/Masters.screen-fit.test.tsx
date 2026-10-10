import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { Context } from '../lib/context';
import { ApiError, useSave } from '../lib/api';
import type { Business, Item, Party } from '../lib/types';
import { MasterForm } from './Masters';

const state = vi.hoisted(() => ({
  save: vi.fn(),
  retry: vi.fn(),
  busy: false,
  uncertain: false,
  error: undefined as Error | undefined,
  data: undefined as { data: Item & Party } | undefined,
}));
vi.mock('../lib/api', () => ({
  ApiError: class extends Error {
    errors: Record<string, string[]>;
    constructor(_status: number, message: string, errors: Record<string, string[]> = {}) {
      super(message);
      this.errors = errors;
    }
  },
  useOnline: () => true,
  send: vi.fn(),
  useSave: vi.fn(() => state),
  useData: () => ({ loading: false, reload: vi.fn(), data: state.data }),
}));
vi.mock('./Records', () => ({ Pagination: () => null }));
vi.mock('./CatalogTools', () => ({ CategoryFilter: () => null }));
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
afterEach(() => {
  state.error = undefined;
  state.busy = false;
  state.uncertain = false;
  state.data = undefined;
  vi.clearAllMocks();
  vi.restoreAllMocks();
});
function page(resource: 'items' | 'contacts', editing = false) {
  return (
    <MemoryRouter initialEntries={[editing ? '/edit/7' : '/new']}>
      <Context.Provider
        value={{
          base: '/api/app/shop',
          path: '/app/shop',
          today: 20830103,
          business: { role: 'owner', pos_profile: 'glass' } as Business,
          revision: 0,
          changed: () => {},
          t: (s) => s,
          locale: 'en',
        }}
      >
        <Routes>
          <Route
            path={editing ? '/edit/:id' : '/new'}
            element={<MasterForm resource={resource} />}
          />
        </Routes>
      </Context.Provider>
    </MemoryRouter>
  );
}
function mobile() {
  vi.spyOn(window, 'matchMedia').mockReturnValue({
    matches: true,
    addEventListener: () => {},
    removeEventListener: () => {},
  } as unknown as MediaQueryList);
}

it('enters all party roles in steps and reviews unchanged contact payload', () => {
  mobile();
  render(page('contacts'));
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Hari' } });
  fireEvent.change(screen.getByLabelText('Phone (optional)'), { target: { value: '9800000000' } });
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Roles' }));
  fireEvent.click(screen.getByLabelText('Supplier'));
  fireEvent.click(screen.getByLabelText('Employee'));
  fireEvent.click(screen.getByLabelText('Rent'));
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Review' }));
  expect(screen.getByText('Hari')).toBeVisible();
  expect(state.save).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button', { name: 'Save' }));
  expect(state.save.mock.calls[0]).toEqual([
    '/api/app/shop/contacts',
    {
      name: 'Hari',
      phone: '9800000000',
      email: null,
      address: null,
      pan: null,
      is_customer: true,
      is_supplier: true,
      is_employee: true,
      is_rent: true,
    },
    expect.any(Function),
    'POST',
  ]);
});

it('reviews exact product price and chosen units without posting on Continue', () => {
  mobile();
  render(page('items'));
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Glass sheet' } });
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Price / unit' }));
  fireEvent.change(screen.getByLabelText('Selling price'), { target: { value: '100.25' } });
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Review' }));
  expect(screen.getByText('Glass sheet')).toBeVisible();
  expect(state.save).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button', { name: 'Save' }));
  expect(state.save.mock.calls[0][1]).toMatchObject({
    name: 'Glass sheet',
    sale_price: '100.25',
    unit_label: 'sq_ft',
    pos_unit: 'sq_ft',
    pos_custom_units: [],
    pos_methods: ['quantity', 'amount', 'pack', 'length', 'area', 'volume'],
  });
});

it('keeps edited party balances behind review disclosure and retains PATCH', () => {
  mobile();
  state.data = {
    data: {
      id: '7',
      name: 'Existing party',
      phone: '9800000000',
      is_customer: true,
      is_supplier: true,
      receivable_paisa: '10125',
      payable_paisa: '25000',
    } as Item & Party,
  };
  render(page('contacts', true));
  expect(screen.getByText('Customer owes / credit')).not.toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Roles' }));
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Review' }));
  fireEvent.click(screen.getByText('Balances / actions'));
  expect(screen.getByText('Customer owes / credit')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Save' }));
  expect(state.save.mock.calls[0][0]).toBe('/api/app/shop/contacts/7');
  expect(state.save.mock.calls[0][3]).toBe('PATCH');
});

it('locks uncertain master inputs and offers original retry', () => {
  mobile();
  state.uncertain = true;
  render(page('items'));
  expect(screen.getByLabelText('Name')).toBeDisabled();
  expect(screen.getByRole('button', { name: '1 Product' })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Retry original action' }));
  expect(state.retry).toHaveBeenCalledOnce();
  expect(state.save).not.toHaveBeenCalled();
});

it('reveals rejected product price step and retains entered name', async () => {
  mobile();
  const view = render(page('items'));
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Glass' } });
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Price / unit' }));
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Review' }));
  state.error = new ApiError(422, 'Check price', { sale_price: ['Invalid price'] });
  view.rerender(page('items'));
  await waitFor(() =>
    expect(screen.getByRole('button', { name: '2 Price / unit' })).toHaveAttribute(
      'aria-current',
      'step',
    ),
  );
  expect(screen.getByLabelText('Name')).toHaveValue('Glass');
});

it('keeps category retry outside locked product fields', () => {
  mobile();
  const categoryRetry = vi.fn();
  vi.mocked(useSave)
    .mockReturnValueOnce({ ...state, clear: vi.fn() })
    .mockReturnValueOnce({ ...state, uncertain: true, retry: categoryRetry, clear: vi.fn() });
  render(page('items'));
  expect(screen.getByLabelText('Name')).toBeDisabled();
  expect(screen.getByRole('button', { name: 'Retry original action' })).toBeEnabled();
  fireEvent.click(screen.getByRole('button', { name: 'Retry original action' }));
  expect(categoryRetry).toHaveBeenCalledOnce();
  expect(state.save).not.toHaveBeenCalled();
});

it('reveals nested measurement options for server validation', async () => {
  mobile();
  const view = render(page('items'));
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Glass' } });
  state.error = new ApiError(422, 'Check measurement', { pos_unit: ['Invalid base unit'] });
  view.rerender(page('items'));
  await waitFor(() =>
    expect(screen.getByRole('button', { name: '3 Review' })).toHaveAttribute(
      'aria-current',
      'step',
    ),
  );
  expect(screen.getByLabelText('Billed base unit')).toBeVisible();
  expect(screen.getByLabelText('Name')).toHaveValue('Glass');
});
