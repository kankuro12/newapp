import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import DocumentForm from './DocumentForm';
import { Context } from '../lib/context';
import type { Business } from '../lib/types';

const saved = vi.hoisted(() => vi.fn());
const fixture = vi.hoisted(() => ({
  id: '6',
  kind: 'quote',
  status: 'draft',
  version: 1,
  business_date_bs: 20830102,
  niche: 'repair',
  title: 'Screen repair',
  reference: 'Device ABC',
  specifications: 'Cracked screen',
  party_snapshot: { id: '2', name: 'Buyer', is_customer: true },
  lines: [
    {
      item_id: '3',
      description: 'Repair',
      unit_snapshot: 'job',
      qty_milli: '2000',
      unit_price_paisa: '10000',
      line_discount_paisa: '1000',
      tax_bps: '0',
      tax_category: 'outside_scope',
    },
  ],
  invoice_discount_paisa: '500',
  total_paisa: '18500',
}));
vi.mock('../lib/api', () => {
  const workflowData = { data: { data: fixture }, loading: false };
  const lookup = { data: { data: { accounts: [], categories: [] } }, loading: false };
  const lists = { data: { data: [], last_page: 1 }, loading: false };
  return {
    ApiError: class extends Error {},
    request: async () => ({ data: [{ item_id: '3', price_paisa: '6000' }] }),
    useData: (path: string) =>
      path?.includes('/workflow/6')
        ? workflowData
        : path?.includes('/price-lists') || path?.includes('/basket-offers')
          ? lists
          : path
            ? lookup
            : { loading: false },
    useSave: () => ({ busy: false, uncertain: false, save: saved }),
    useOnline: () => true,
  };
});
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

describe('workflow editor', () => {
  it('copies quote details and saves an estimate without posting or payment fields', async () => {
    Object.defineProperty(window, 'matchMedia', {
      writable: true,
      value: () => ({ matches: false, addEventListener: () => {}, removeEventListener: () => {} }),
    });
    const business = {
      role: 'owner',
      opening_finalized_at: null,
      tax_recording_enabled: false,
    } as Business;
    render(
      <MemoryRouter initialEntries={['/?copy=6']}>
        <Context.Provider
          value={{
            base: '/api/app/shop',
            path: '/app/shop',
            business,
            today: 20830102,
            revision: 0,
            changed: () => {},
            t: (value) => value,
            locale: 'en',
          }}
        >
          <DocumentForm workflowKind="quote" />
        </Context.Provider>
      </MemoryRouter>,
    );
    await waitFor(() =>
      expect(screen.getByLabelText('Job title').getAttribute('value')).toBe('Screen repair'),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save quote' }));
    await waitFor(() => expect(saved).toHaveBeenCalled());
    expect(saved.mock.calls[0][0]).toBe('/api/app/shop/workflows');
    const payload = saved.mock.calls[0][1];
    expect(payload.kind).toBe('quote');
    expect(payload.expected_total_paisa).toBe('18500');
    expect(payload.reference).toBe('Device ABC');
    expect(payload.paid_now).toBeUndefined();
    expect(payload.money_account_id).toBeUndefined();
    expect(screen.queryByRole('button', { name: 'Post sale' })).toBeNull();
  });
  it('applies current party prices only after explicit review', async () => {
    saved.mockClear();
    Object.defineProperty(window, 'matchMedia', {
      writable: true,
      value: () => ({ matches: false, addEventListener: () => {}, removeEventListener: () => {} }),
    });
    const business = {
      role: 'owner',
      opening_finalized_at: null,
      tax_recording_enabled: false,
    } as Business;
    render(
      <MemoryRouter initialEntries={['/?copy=6']}>
        <Context.Provider
          value={{
            base: '/api/app/shop',
            path: '/app/shop',
            business,
            today: 20830102,
            revision: 0,
            changed: () => {},
            t: (value) => value,
            locale: 'en',
          }}
        >
          <DocumentForm workflowKind="quote" />
        </Context.Provider>
      </MemoryRouter>,
    );
    await waitFor(() =>
      expect(screen.getByLabelText('Job title').getAttribute('value')).toBe('Screen repair'),
    );
    expect(screen.getByLabelText('Price').getAttribute('value')).toBe('100.00');
    fireEvent.click(screen.getByRole('button', { name: 'Review quantity prices' }));
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Apply reviewed prices' })).toBeEnabled(),
    );
    expect(screen.getByLabelText('Price').getAttribute('value')).toBe('100.00');
    fireEvent.click(screen.getByRole('button', { name: 'Apply reviewed prices' }));
    expect(screen.getByLabelText('Price').getAttribute('value')).toBe('60.00');
    fireEvent.click(screen.getByRole('button', { name: 'Save quote' }));
    await waitFor(() => expect(saved).toHaveBeenCalled());
    expect(saved.mock.calls[0][1].expected_total_paisa).toBe('10500');
  });
});
