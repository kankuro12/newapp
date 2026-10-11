import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { Context } from '../lib/context';
import type { Business } from '../lib/types';
import Gym from './Gym';
import More from './More';

vi.mock('@ionic/react', () => ({
  IonButton: ({ children, onClick }: { children: React.ReactNode; onClick?: () => void }) => (
    <button onClick={onClick}>{children}</button>
  ),
  IonIcon: () => null,
  IonSpinner: () => null,
}));

afterEach(() => vi.unstubAllGlobals());

function page(element: React.ReactNode, profile = 'gym', role = 'owner') {
  return (
    <MemoryRouter>
      <Context.Provider
        value={{
          business: { role, pos_profile: profile } as Business,
          base: '/api/app/gym',
          path: '/app/gym',
          today: 20830103,
          revision: 0,
          changed: vi.fn(),
          t: (s) => s,
          locale: 'en',
        }}
      >
        {element}
      </Context.Provider>
    </MemoryRouter>
  );
}

it('lists members with current membership and opens plans for managers', async () => {
  const fetch = vi.fn(async (url: string) => {
    const body = url.includes('/gym/day')
      ? { data: { checkins: [], classes: [], expiring: [] } }
      : url.includes('/gym/members')
        ? {
            data: [
              {
                id: '1',
                name: 'Asha Member',
                member_code: 'M0001',
                phone: null,
                contact_id: '9',
                current: {
                  id: '5',
                  start_bs: 20830101,
                  end_bs: 20830130,
                  status: 'active',
                  visits_used: 0,
                  version: 1,
                  plan_snapshot: { name: 'Monthly', fee_paisa: '150000' },
                },
              },
            ],
          }
        : { data: [] };
    return new Response(JSON.stringify(body), { headers: { 'Content-Type': 'application/json' } });
  });
  vi.stubGlobal('fetch', fetch);
  render(page(<Gym />));
  await waitFor(() => expect(screen.getByText('Asha Member')).toBeInTheDocument());
  expect(screen.getByText(/Monthly · until/)).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Plans' }));
  expect(await screen.findByRole('button', { name: 'New plan' })).toBeInTheDocument();
});

it('shows the gym desk only for gym businesses', () => {
  const { unmount } = render(page(<More />));
  expect(screen.getByRole('link', { name: 'Gym desk' })).toHaveAttribute('href', '/app/gym/gym');
  unmount();
  render(page(<More />, 'general'));
  expect(screen.queryByRole('link', { name: 'Gym desk' })).not.toBeInTheDocument();
});
