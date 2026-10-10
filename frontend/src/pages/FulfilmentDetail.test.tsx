import { render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import FulfilmentDetail from './FulfilmentDetail';

const fixture = vi.hoisted(() => ({ status: 'posted', role: 'owner' }));
vi.mock('../lib/context', () => ({
  useWorkspace: () => ({
    base: '/api/app/shop',
    path: '/app/shop',
    revision: 0,
    t: (s: string) => s,
    changed: vi.fn(),
    business: { role: fixture.role, name: 'Shop today' },
  }),
}));
vi.mock('../lib/api', () => ({
  ApiError: class extends Error {},
  useData: (path: string) => ({
    loading: false,
    data: {
      data: path.includes('/fulfilment/')
        ? {
            id: '8',
            workflow_id: '9',
            number: 'DEL-000008',
            kind: 'delivery',
            status: fixture.status,
            business_date_bs: 20830103,
            version: 1,
            lines: [
              {
                id: '81',
                position: 1,
                qty_milli: '1250',
                billable_qty_milli: '1250',
                returnable_qty_milli: '1250',
                item_snapshot: { description: 'Milk', unit_snapshot: 'L' },
              },
            ],
          }
        : {
            id: '9',
            number: 'SO-000009',
            business_snapshot: { name: 'Original shop' },
            party_snapshot: { name: 'Original customer' },
            lines: [{ description: 'Milk', qty_milli: '5000' }],
          },
    },
  }),
}));
vi.mock('../components/FulfilmentEntry', () => ({ FulfilmentEntry: () => null }));
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

it('prints actual action quantities rather than the entire order', () => {
  fixture.status = 'posted';
  fixture.role = 'owner';
  render(
    <MemoryRouter>
      <FulfilmentDetail />
    </MemoryRouter>,
  );
  expect(screen.getByText('1.250 L')).toBeInTheDocument();
  expect(screen.queryByText('5.000 L')).not.toBeInTheDocument();
  expect(screen.getByText('Original customer')).toBeInTheDocument();
  expect(
    screen.getByText('Quantity record only. Billing and payment are recorded separately.'),
  ).toBeInTheDocument();
});

it('does not label a cancelled historical slip with the now-editable customer or offer cashier cancellation', () => {
  fixture.status = 'cancelled';
  fixture.role = 'cashier';
  const view = render(
    <MemoryRouter>
      <FulfilmentDetail />
    </MemoryRouter>,
  );
  expect(screen.queryByText('Original customer')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Cancel recorded action' })).not.toBeInTheDocument();
  expect(
    within(view.container.querySelector('.invoice') as HTMLElement).getByText('cancelled'),
  ).toBeInTheDocument();
});
