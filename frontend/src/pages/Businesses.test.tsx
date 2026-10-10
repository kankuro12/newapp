import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import type { User } from '../lib/types';
import Businesses from './Businesses';

const state = vi.hoisted(() => ({
  save: vi.fn(),
  retry: vi.fn(),
  busy: false,
  uncertain: false,
  error: undefined,
  count: 5,
}));
vi.mock('../lib/api', () => ({
  ApiError: class extends Error {},
  useOnline: () => true,
  useSave: () => state,
  request: vi.fn(),
  resetSession: vi.fn(),
  send: vi.fn(),
  useData: (url: string) => ({
    loading: false,
    reload: () => {},
    data: {
      data:
        url === '/api/billing/accounts'
          ? [{ id: '7', name: 'Owner account', role: 'owner' }]
          : Array.from({ length: state.count }, (_, i) => ({
              id: String(i + 1),
              name: `Branch ${i + 1}`,
              slug: `branch-${i + 1}`,
              role: 'owner',
              access_status: 'active',
            })),
    },
  }),
}));
vi.mock('@ionic/react', () => ({
  IonPage: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  IonContent: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
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
  state.count = 5;
  state.uncertain = false;
  state.busy = false;
  vi.clearAllMocks();
});
function page() {
  return (
    <MemoryRouter>
      <Businesses user={{ name: 'Owner' } as User} logout={() => {}} />
    </MemoryRouter>
  );
}

it('pages every branch and focuses business creation without the branch list', () => {
  render(page());
  expect(screen.getByText('Branch 1')).toBeVisible();
  expect(screen.queryByText('Branch 3')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Next businesses' }));
  expect(screen.getByText('Branch 3')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Next businesses' }));
  expect(screen.getByRole('link', { name: /Branch 5/ })).toHaveAttribute('href', '/app/branch-5');
  fireEvent.click(screen.getByRole('button', { name: 'New business' }));
  expect(screen.queryByText('Branch 5')).not.toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('Business name'), { target: { value: 'New shop' } });
  fireEvent.change(screen.getByLabelText('Address'), { target: { value: 'Kathmandu' } });
  fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
  expect(screen.getByText('Branch 5')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'New business' }));
  expect(screen.getByLabelText('Business name')).toHaveValue('New shop');
  fireEvent.change(screen.getByLabelText('Business type'), { target: { value: 'restaurant' } });
  fireEvent.click(screen.getByRole('button', { name: 'Create business' }));
  expect(state.save.mock.calls[0]).toEqual([
    '/api/businesses',
    { name: 'New shop', address: 'Kathmandu', business_type: 'restaurant' },
    expect.any(Function),
  ]);
});

it('shows only first-business setup when empty and locks uncertain setup', () => {
  state.count = 0;
  state.uncertain = true;
  render(page());
  expect(screen.getByLabelText('Business name')).toBeDisabled();
  expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Retry original action' }));
  expect(state.retry).toHaveBeenCalledOnce();
  expect(state.save).not.toHaveBeenCalled();
});
