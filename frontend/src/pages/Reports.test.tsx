import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { Context } from '../lib/context';
import type { Business } from '../lib/types';
import Reports, { ReportTable } from './Reports';

const sample = vi.hoisted(() => ({
  rows: Array.from({ length: 5 }, (_, i) => ({
    name: `Party ${i + 1}`,
    due_paisa: '12525',
    business_date_bs: 20830103,
  })),
  overview: {
    sales_paisa: '30000',
    cogs_paisa: '5000',
    profit_paisa: '25000',
    inventory_paisa: '10000',
    cash_paisa: '10125',
    receivables_paisa: '20125',
    customer_credits_paisa: '0',
    payables_paisa: '25125',
    supplier_advances_paisa: '0',
    bank_overdrafts_paisa: '0',
  },
  calls: [] as (string | null)[],
}));
vi.mock('../lib/api', () => ({
  ApiError: class extends Error {},
  useData: (url: string | null) => {
    sample.calls.push(url);
    return {
      loading: false,
      reload: () => {},
      data: url?.includes('/lookup')
        ? { data: { accounts: [], channels: { payables_id: '2' } } }
        : url
          ? { data: url.includes('/overview?') ? sample.overview : sample.rows }
          : undefined,
    };
  },
}));
vi.mock('../components/Picker', () => ({
  default: ({ onPick }: { onPick: (row: { id: string; name: string }) => void }) => (
    <button type="button" onClick={() => onPick({ id: '9', name: 'Hari' })}>
      Pick party
    </button>
  ),
}));
vi.mock('@ionic/react', () => ({
  IonButton: ({ children, ...props }: React.ButtonHTMLAttributes<HTMLButtonElement>) => (
    <button {...props}>{children}</button>
  ),
  IonIcon: () => null,
  IonSpinner: () => null,
}));
afterEach(() => {
  sample.calls = [];
  vi.restoreAllMocks();
});
function mobile() {
  vi.spyOn(window, 'matchMedia').mockReturnValue({
    matches: true,
    addEventListener: () => {},
    removeEventListener: () => {},
  } as unknown as MediaQueryList);
}
function page(route = '/?report=receivables', role = 'owner') {
  return (
    <MemoryRouter initialEntries={[route]}>
      <Context.Provider
        value={{
          base: '/api/app/shop',
          path: '/app/shop',
          revision: 0,
          today: 20830103,
          changed: () => {},
          locale: 'en',
          t: (s) => s,
          business: { role } as Business,
        }}
      >
        <Reports />
      </Context.Provider>
    </MemoryRouter>
  );
}

it('pages all report rows and keeps exact values and all rows available for print', () => {
  mobile();
  render(<ReportTable rows={sample.rows} />);
  expect(screen.getByText('Party 1')).toBeVisible();
  expect(screen.getByText('Party 3')).not.toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Next rows' }));
  expect(screen.getByText('Party 3')).toBeVisible();
  expect(screen.getByText('Party 1')).not.toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Next rows' }));
  expect(screen.getByText('Party 5')).toBeVisible();
  expect(screen.getByRole('button', { name: 'Next rows' })).toBeDisabled();
  expect(screen.getAllByText('रु 125.25')).toHaveLength(5);
  expect(screen.getAllByText('2083-01-03')).toHaveLength(5);
  expect(screen.getByText('Party 1').closest('tr')).toHaveAttribute('data-print-row');
});

it('opens filters separately and preserves report exports and chosen BS range', () => {
  mobile();
  render(page());
  expect(screen.getByText('Party 1')).toBeVisible();
  expect(screen.getByLabelText('From (BS)')).not.toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Filters' }));
  fireEvent.change(screen.getByLabelText('From (BS)'), { target: { value: '2083-01-02' } });
  fireEvent.click(screen.getByRole('button', { name: 'Show results' }));
  expect(screen.getByText('Party 1')).toBeVisible();
  expect(screen.getByRole('link', { name: 'Download CSV' })).toHaveAttribute(
    'href',
    '/api/app/shop/reports/receivables/export?from=2083-01-02&to=2083-01-03',
  );
});

it('starts empty statements in filters and preserves party and supplier-channel scope', () => {
  mobile();
  render(page('/?report=statement'));
  expect(screen.getByRole('button', { name: 'Filters' })).toHaveAttribute('aria-pressed', 'true');
  fireEvent.click(screen.getByRole('button', { name: 'Pick party' }));
  fireEvent.change(screen.getByLabelText('Account'), { target: { value: '2' } });
  fireEvent.click(screen.getByRole('button', { name: 'Show results' }));
  expect(sample.calls).toContain(
    '/api/app/shop/reports/statement?from=2083-01-01&to=2083-01-03&contact_id=9&account_id=2',
  );
});

it('retains cashier export restriction', () => {
  mobile();
  render(page('/?report=sales', 'cashier'));
  expect(screen.queryByRole('link', { name: 'Download CSV' })).not.toBeInTheDocument();
});

it('separates overview totals from balances without dropping either set', () => {
  mobile();
  render(page('/?report=overview'));
  expect(screen.getByText('रु 300.00')).toBeVisible();
  expect(screen.getByText('रु 101.25')).not.toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Balances' }));
  expect(screen.getByText('रु 101.25')).toBeVisible();
  expect(screen.getByText('रु 300.00')).not.toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Results' }));
  expect(screen.getByText('रु 300.00')).toBeVisible();
});

it('clamps shorter refreshed reports to their final available page', () => {
  mobile();
  const view = render(<ReportTable rows={sample.rows} />);
  fireEvent.click(screen.getByRole('button', { name: 'Next rows' }));
  fireEvent.click(screen.getByRole('button', { name: 'Next rows' }));
  view.rerender(<ReportTable rows={sample.rows.slice(0, 1)} />);
  expect(screen.getByText('Party 1')).toBeVisible();
  expect(screen.queryByRole('button', { name: 'Next rows' })).not.toBeInTheDocument();
});
