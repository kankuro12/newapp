import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import Billing from './Billing';
vi.mock('../lib/api', () => ({
  useData: (url: string) => ({
    loading: false,
    reload: vi.fn(),
    data: {
      data:
        url === '/api/billing/accounts'
          ? [{ id: '7', name: 'Owner account', role: 'owner' }]
          : url === '/api/billing/packages'
            ? [
                {
                  id: '2',
                  name: 'Gym package',
                  duration_days: 30,
                  trial_days: 7,
                  max_businesses: 3,
                  products: ['gym'],
                  prices: [{ currency: 'NPR', amount_minor: '123456789012' }],
                },
              ]
            : {
                status: 'expired',
                end_at: '2026-10-01 00:00:00',
                products: ['gym'],
                max_businesses: 3,
                package: { name: 'Original terms' },
              },
    },
  }),
}));
vi.mock('./BillingCheckout', () => ({ default: () => null }));
vi.mock('@ionic/react', () => ({
  IonPage: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  IonContent: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  IonSpinner: () => null,
  IonIcon: () => null,
  IonButton: ({ children }: { children: React.ReactNode }) => <button>{children}</button>,
}));
it('shows expired account terms and precise catalogue prices outside business access', () => {
  render(
    <MemoryRouter>
      <Billing />
    </MemoryRouter>,
  );
  expect(screen.getByText('Owner account')).toBeVisible();
  expect(screen.getByText('expired')).toBeVisible();
  expect(screen.getByText('Original terms')).toBeVisible();
  expect(screen.getByText('NPR 1234567890.12')).toBeVisible();
  expect(screen.getByRole('link', { name: 'Businesses' })).toHaveAttribute('href', '/businesses');
});
