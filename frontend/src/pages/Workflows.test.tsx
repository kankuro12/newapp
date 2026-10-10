import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { WorkflowDetail } from './Workflows';

const fixture = vi.hoisted(() => ({ row: {} as Record<string, unknown> }));
vi.mock('../lib/context', () => ({
  useWorkspace: () => ({
    base: '/api/app/shop',
    path: '/app/shop',
    revision: 0,
    t: (s: string) => s,
    today: 20830103,
    changed: vi.fn(),
    business: { role: 'owner' },
  }),
}));
vi.mock('../lib/api', () => ({
  ApiError: class extends Error {},
  useOnline: () => true,
  useData: (path: string) => ({
    loading: false,
    data: { data: path.endsWith('/lookup') ? { accounts: [] } : fixture.row },
  }),
  useSave: () => ({ save: vi.fn(), clear: vi.fn() }),
}));
vi.mock('./DocumentForm', () => ({ default: () => null }));
vi.mock('./Records', () => ({ Pagination: () => null }));
vi.mock('@ionic/react', () => ({
  IonButton: ({
    children,
    type = 'button',
    fill,
    color,
    ...props
  }: React.ButtonHTMLAttributes<HTMLButtonElement> & { fill?: string; color?: string }) => (
    <button type={type} data-fill={fill} data-color={color} {...props}>
      {children}
    </button>
  ),
  IonIcon: () => null,
  IonSpinner: () => null,
}));

function renderOrder(active: boolean, remaining = '1000') {
  fixture.row = {
    id: '9',
    kind: 'sales_order',
    number: 'SO-000009',
    status: 'open',
    version: 2,
    business_date_bs: 20830103,
    party_snapshot: { name: 'Customer' },
    business_snapshot: { name: 'Shop' },
    lines: [],
    subtotal_paisa: '100',
    line_discount_paisa: '0',
    invoice_discount_paisa: '0',
    tax_paisa: '0',
    total_paisa: '100',
    fulfilment: {
      active,
      lines: [
        {
          position: 1,
          description: 'Milk',
          unit_snapshot: 'L',
          remaining_qty_milli: remaining,
          ordered_qty_milli: '3000',
          completed_qty_milli: '0',
          billed_qty_milli: '0',
          billable_qty_milli: '0',
          billed_returned_qty_milli: '0',
        },
      ],
      activity: [],
      bills: [],
    },
  };
  return render(
    <MemoryRouter>
      <WorkflowDetail />
    </MemoryRouter>,
  );
}

it('keeps full-order actions available before fulfilment', () => {
  renderOrder(false);
  expect(screen.getByRole('button', { name: 'Review + create bill' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Edit' })).toBeInTheDocument();
  expect(screen.getByRole('option', { name: 'Delivery note' })).toBeInTheDocument();
  expect(
    screen.getByText(
      'Nonfinancial record. No stock reservation, delivery posting or payment recorded.',
    ),
  ).toBeInTheDocument();
});

it('prevents active partial orders from presenting a full bill, premature completion or full delivery slip', () => {
  renderOrder(true);
  expect(screen.queryByRole('button', { name: 'Review + create bill' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Mark fulfilled' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Edit' })).not.toBeInTheDocument();
  expect(screen.queryByRole('option', { name: 'Delivery note' })).not.toBeInTheDocument();
  expect(
    screen.getByText('Order summary. See delivery/receipt and bill records for posted quantities.'),
  ).toBeInTheDocument();
});

it('keeps an open quantity entry mounted when another actor converts the order', () => {
  const view = renderOrder(false);
  fireEvent.click(screen.getByRole('button', { name: 'Deliver goods / complete work' }));
  fixture.row = { ...fixture.row, document_id: '20', status: 'converted', version: 3 };
  view.rerender(
    <MemoryRouter>
      <WorkflowDetail />
    </MemoryRouter>,
  );
  expect(
    screen.getByRole('heading', { name: 'Deliver goods / complete work' }),
  ).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Close entry' })).toBeInTheDocument();
});
