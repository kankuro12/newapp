import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { Context } from '../lib/context';
import type { Business, Item, Party } from '../lib/types';
import { ApiError } from '../lib/api';
import { CountForm, MoneyForm, ReturnForm } from './DailyForms';

const state = vi.hoisted(() => ({
  busy: false,
  uncertain: false,
  error: undefined as Error | undefined,
  save: vi.fn(),
  retry: vi.fn(),
  clear: vi.fn(),
}));
vi.mock('../lib/api', () => ({
  ApiError: class extends Error {
    status: number;
    errors: Record<string, string[]>;
    constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
      super(message);
      this.status = status;
      this.errors = errors;
    }
  },
  useOnline: () => true,
  useSave: () => state,
  useData: (url: string | null) => ({
    loading: false,
    reload: () => {},
    data: !url
      ? undefined
      : {
          data: url?.endsWith('/lookup')
            ? {
                accounts: [
                  { id: '1', name: 'Cash' },
                  { id: '2', name: 'Bank' },
                ],
                categories: [],
              }
            : url?.includes('/payments/preview?')
              ? {
                  bills: [
                    {
                      id: '31',
                      number: 'S31',
                      available_paisa: '100000',
                      suggested_paisa: '12525',
                    },
                  ],
                  unallocated_paisa: '0',
                }
              : url?.endsWith('/document/7')
                ? {
                    id: '7',
                    number: 'S7',
                    type: 'sale',
                    lines: Array.from({ length: 5 }, (_, i) => ({
                      id: String(i + 1),
                      description: `Item ${i + 1}`,
                      unit_snapshot: 'kg',
                      qty_milli: '3000',
                      returnable_qty_milli: '2000',
                      net_base_paisa: '1',
                      tax_paisa: '0',
                    })),
                  }
                : url?.includes('/stock-adjustments?')
                  ? [
                      {
                        id: '21',
                        reason: 'Previous count',
                        status: 'cancelled',
                        business_date_bs: 20830103,
                        qty_delta_milli: '1000',
                      },
                    ]
                  : undefined,
          last_page: 1,
        },
  }),
}));
vi.mock('../components/Picker', () => ({
  default: ({ kind, onPick }: { kind: string; onPick: (row: Item | Party) => void }) => (
    <button
      type="button"
      onClick={() =>
        onPick(
          kind === 'items'
            ? ({
                id: '5',
                name: 'Rice',
                kind: 'stock',
                qty_milli: '2000',
                unit_label: 'kg',
              } as Item)
            : ({ id: '9', name: 'Hari' } as Party),
        )
      }
    >
      Pick {kind}
    </button>
  ),
}));
vi.mock('@ionic/react', () => ({
  IonIcon: () => null,
  IonSpinner: () => null,
  IonButton: ({
    children,
    type = 'button',
    ...props
  }: React.ButtonHTMLAttributes<HTMLButtonElement>) => (
    <button type={type} {...props}>
      {children}
    </button>
  ),
}));
afterEach(() => {
  state.error = undefined;
  state.uncertain = false;
  state.busy = false;
  vi.clearAllMocks();
  vi.restoreAllMocks();
});
function page(route: string, role = 'owner') {
  return (
    <MemoryRouter initialEntries={[route]}>
      <Context.Provider
        value={{
          base: '/api/app/shop',
          path: '/app/shop',
          business: { role } as Business,
          today: 20830103,
          revision: 0,
          changed: () => {},
          t: (s) => s,
          locale: 'en',
        }}
      >
        <Routes>
          <Route path="/money/:kind/new" element={<MoneyForm />} />
          <Route path="/document/:id/return" element={<ReturnForm />} />
          <Route path="/count" element={<CountForm />} />
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

it.each([
  'receipt',
  'supplier_payment',
  'customer_refund',
  'supplier_refund',
  'transfer',
  'contribution',
  'withdrawal',
])('reviews %s in steps and retains original posting fields', (kind) => {
  mobile();
  render(page(`/money/${kind}/new`));
  const party = !['transfer', 'contribution', 'withdrawal'].includes(kind);
  if (party) fireEvent.click(screen.getByRole('button', { name: 'Pick contacts' }));
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Amount' }));
  fireEvent.change(screen.getByLabelText('Amount (NPR)'), { target: { value: '125.25' } });
  fireEvent.keyDown(screen.getByLabelText('Amount (NPR)'), { key: 'Enter' });
  expect(screen.getByRole('button', { name: '3 Review' })).toHaveAttribute('aria-current', 'step');
  expect(state.save).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button', { name: 'Save' }));
  const owner = ['contribution', 'withdrawal'].includes(kind);
  expect(state.save.mock.calls[0][0]).toBe(`/api/app/shop/${owner ? 'owner-money' : 'payments'}`);
  expect(state.save.mock.calls[0][1]).toEqual({
    kind,
    amount: '125.25',
    business_date_bs: '2083-01-03',
    money_account_id: '1',
    notes: '',
    overdraft_confirmed: false,
    ...(kind === 'transfer'
      ? { destination_account_id: '2' }
      : party
        ? { contact_id: '9', unallocated_confirmed: false }
        : {}),
  });
});

it('retains selected bill allocation through payment review', () => {
  mobile();
  render(page('/money/receipt/new?document=31&amount=125.25'));
  fireEvent.click(screen.getByRole('button', { name: 'Pick contacts' }));
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Amount' }));
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Review' }));
  fireEvent.click(screen.getByRole('button', { name: 'Save' }));
  expect(state.save.mock.calls[0][1]).toMatchObject({
    allocations: [{ document_id: '31', amount: '125.25' }],
  });
});

it('pages every return item without losing quantities or original penny allocation', () => {
  mobile();
  render(page('/document/7/return'));
  fireEvent.change(screen.getByLabelText('Quantity · Item 1'), { target: { value: '1' } });
  fireEvent.click(screen.getByRole('button', { name: 'Next items' }));
  expect(screen.getByText('Item 3')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Next items' }));
  fireEvent.change(screen.getByLabelText('Quantity · Item 5'), { target: { value: '1' } });
  fireEvent.click(screen.getByRole('button', { name: 'Previous items' }));
  fireEvent.click(screen.getByRole('button', { name: 'Previous items' }));
  expect(screen.getByLabelText('Quantity · Item 1')).toHaveValue('1');
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Reason' }));
  fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'Damaged goods' } });
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Review' }));
  expect(screen.getByText('रु 0.02')).toBeInTheDocument();
  expect(state.save).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button', { name: 'Create return' }));
  expect(state.save.mock.calls[0][1]).toEqual({
    lines: [
      { source_line_id: '1', qty: '1' },
      { source_line_id: '5', qty: '1' },
    ],
    business_date_bs: '2083-01-03',
    reason: 'Damaged goods',
    refund_now: false,
    money_account_id: '1',
  });
});

it('reviews counted stock and keeps history separate from entry', () => {
  mobile();
  render(page('/count'));
  expect(screen.queryByText('Previous count')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Pick items' }));
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Count' }));
  fireEvent.change(screen.getByLabelText('Physically counted quantity'), {
    target: { value: '3.125' },
  });
  fireEvent.change(screen.getByLabelText('Cost per extra unit (NPR)'), {
    target: { value: '25.50' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Review' }));
  fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'Actual shelf count' } });
  expect(state.save).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button', { name: 'Save count' }));
  expect(state.save.mock.calls[0][1]).toEqual({
    item_id: '5',
    counted_qty: '3.125',
    expected_qty_milli: '2000',
    unit_cost: '25.50',
    business_date_bs: '2083-01-03',
    reason: 'Actual shelf count',
    zero_cost_confirmed: false,
  });
  fireEvent.click(screen.getByRole('button', { name: 'Count history' }));
  expect(screen.getByText('Previous count')).toBeInTheDocument();
  expect(screen.queryByLabelText('Physically counted quantity')).not.toBeInTheDocument();
});

it.each(['/money/receipt/new', '/document/7/return', '/count'])(
  'locks uncertain %s and retries original action',
  (route) => {
    mobile();
    state.uncertain = true;
    render(page(route));
    expect(
      screen.getByRole('button', {
        name: '1 ' + (route.startsWith('/money') ? 'Party' : route === '/count' ? 'Item' : 'Items'),
      }),
    ).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Retry original action' }));
    expect(state.retry).toHaveBeenCalledOnce();
    expect(state.save).not.toHaveBeenCalled();
  },
);

it('returns rejected amount to its entry step with party retained', async () => {
  mobile();
  const view = render(page('/money/receipt/new'));
  fireEvent.click(screen.getByRole('button', { name: 'Pick contacts' }));
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Amount' }));
  fireEvent.change(screen.getByLabelText('Amount (NPR)'), { target: { value: '125.25' } });
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Review' }));
  state.error = new ApiError(422, 'Check amount', { amount: ['Rejected amount'] });
  view.rerender(page('/money/receipt/new'));
  await waitFor(() =>
    expect(screen.getByRole('button', { name: '2 Amount' })).toHaveAttribute(
      'aria-current',
      'step',
    ),
  );
  expect(screen.getByText('Hari')).toBeInTheDocument();
});

it('preserves reviewed manual allocation, notes and explicit advance', () => {
  mobile();
  render(page('/money/receipt/new'));
  fireEvent.click(screen.getByRole('button', { name: 'Pick contacts' }));
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Amount' }));
  fireEvent.change(screen.getByLabelText('Amount (NPR)'), { target: { value: '125.25' } });
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Review' }));
  const bills = screen.getByText('Choose bills').closest('details')!;
  bills.open = true;
  fireEvent(bills, new Event('toggle'));
  fireEvent.change(screen.getByLabelText('S31 · Available रु 1,000.00'), {
    target: { value: '100.00' },
  });
  fireEvent.click(
    screen.getByLabelText(
      'Explicitly keep unapplied amount as advance / starting-balance settlement',
    ),
  );
  fireEvent.change(screen.getByLabelText('Notes'), { target: { value: 'Rest is advance' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save' }));
  expect(state.save.mock.calls[0][1]).toMatchObject({
    amount: '125.25',
    notes: 'Rest is advance',
    unallocated_confirmed: true,
    allocations: [{ document_id: '31', amount: '100.00' }],
  });
});

it('retains optional immediate return refund and chosen account', () => {
  mobile();
  render(page('/document/7/return'));
  fireEvent.change(screen.getByLabelText('Quantity · Item 1'), { target: { value: '1' } });
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Reason' }));
  fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'Damaged goods' } });
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Review' }));
  fireEvent.click(screen.getByLabelText('Refund customer now'));
  fireEvent.change(screen.getByLabelText('Payment account'), { target: { value: '2' } });
  fireEvent.click(screen.getByRole('button', { name: 'Create return' }));
  expect(state.save.mock.calls[0][1]).toMatchObject({
    refund_now: true,
    money_account_id: '2',
    lines: [{ source_line_id: '1', qty: '1' }],
  });
});

it('reveals the rejected return line on its original page without losing other selections', async () => {
  mobile();
  const view = render(page('/document/7/return'));
  fireEvent.change(screen.getByLabelText('Quantity · Item 1'), { target: { value: '1' } });
  fireEvent.click(screen.getByRole('button', { name: 'Next items' }));
  fireEvent.click(screen.getByRole('button', { name: 'Next items' }));
  fireEvent.change(screen.getByLabelText('Quantity · Item 5'), { target: { value: '1' } });
  fireEvent.click(screen.getByRole('button', { name: 'Previous items' }));
  fireEvent.click(screen.getByRole('button', { name: 'Previous items' }));
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Reason' }));
  fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'Damaged goods' } });
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Review' }));
  state.error = new ApiError(422, 'Check quantity', { 'lines.1.qty': ['Quantity changed'] });
  view.rerender(page('/document/7/return'));
  await waitFor(() =>
    expect(screen.getByRole('button', { name: '1 Items' })).toHaveAttribute('aria-current', 'step'),
  );
  expect(screen.getByText('Item 5')).toBeVisible();
  expect(screen.getByLabelText('Quantity · Item 1')).toHaveValue('1');
  expect(screen.getByLabelText('Quantity · Item 5')).toHaveValue('1');
});

it.each(['/money/withdrawal/new', '/count'])('retains cashier restriction for %s', (route) => {
  render(page(route, 'cashier'));
  expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Save count' })).not.toBeInTheDocument();
  expect(state.save).not.toHaveBeenCalled();
});
