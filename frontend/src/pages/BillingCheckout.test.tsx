import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import BillingCheckout from './BillingCheckout';
const state = vi.hoisted(() => ({ save: vi.fn(), payments: [] as unknown[], uncertain: false }));
vi.mock('../lib/api', () => ({
  useOnline: () => true,
  useSave: () => ({ busy: false, uncertain: state.uncertain, save: state.save, retry: vi.fn() }),
  useData: (url: string) => ({
    loading: false,
    reload: vi.fn(),
    data: {
      data: url.endsWith('/businesses')
        ? [{ id: '9', name: 'Main shop', business_type: 'general', package_enabled: true }]
        : state.payments,
    },
  }),
}));
vi.mock('@ionic/react', () => ({
  IonButton: ({ children, ...props }: React.ButtonHTMLAttributes<HTMLButtonElement>) => (
    <button {...props}>{children}</button>
  ),
  IonSpinner: () => null,
  IonIcon: () => null,
}));
const plans = [
  {
    id: '2',
    version: 4,
    name: 'Standard',
    duration_days: 30,
    trial_days: 7,
    max_businesses: 1,
    products: ['bookkeeping'],
    prices: [{ currency: 'NPR', amount_minor: '999999999999' }],
  },
];
const gateways = [
  { id: 'esewa', currencies: ['NPR'] },
  { id: 'paypal', currencies: ['USD', 'EUR'] },
];
function show() {
  return render(
    <MemoryRouter>
      <BillingCheckout accountId="7" packages={plans} gateways={gateways} onRenewed={vi.fn()} />
    </MemoryRouter>,
  );
}
beforeEach(() => {
  state.save.mockReset();
  state.payments = [];
  state.uncertain = false;
});
it('reviews exact price and posts frozen version with selected businesses', () => {
  show();
  expect(screen.getByText('NPR 9999999999.99')).toBeVisible();
  expect(screen.queryByRole('option', { name: 'PayPal' })).not.toBeInTheDocument();
  fireEvent.click(screen.getByLabelText('I reviewed package terms and price'));
  fireEvent.click(screen.getByRole('button', { name: 'Create checkout' }));
  expect(state.save).toHaveBeenCalledWith(
    '/api/billing/accounts/7/checkout',
    {
      package_id: '2',
      expected_version: 4,
      gateway: 'esewa',
      currency: 'NPR',
      retained_business_ids: ['9'],
    },
    expect.any(Function),
  );
});
it('unresolved payment blocks another checkout but permits verification', () => {
  state.payments = [
    {
      reference: 'old-payment',
      gateway: 'esewa',
      status: 'unknown',
      currency: 'NPR',
      amount_minor: '10000',
      checkout: null,
    },
  ];
  show();
  expect(screen.getByRole('button', { name: 'Create checkout' })).toBeDisabled();
  expect(screen.getByRole('button', { name: 'Check payment status' })).toBeEnabled();
});
it('uncertain request locks original checkout fields', () => {
  state.uncertain = true;
  show();
  expect(screen.getByLabelText('Package')).toBeDisabled();
  expect(screen.getByRole('button', { name: 'Retry original action' })).toBeVisible();
});
it('renders signed eSewa form with exact hidden values', () => {
  state.payments = [
    {
      reference: 'payment',
      gateway: 'esewa',
      status: 'pending',
      currency: 'NPR',
      amount_minor: '10001',
      checkout: {
        type: 'form',
        url: 'https://rc-epay.esewa.com.np/api/epay/main/v2/form',
        fields: { total_amount: '100.01', signature: 'signed-value' },
      },
    },
  ];
  show();
  const button = screen.getByRole('button', { name: 'Continue with eSewa' });
  expect(button.closest('form')).toHaveAttribute(
    'action',
    'https://rc-epay.esewa.com.np/api/epay/main/v2/form',
  );
  expect(button.closest('form')?.querySelector('input[name="total_amount"]')).toHaveValue('100.01');
});
