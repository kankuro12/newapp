import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { Opening } from './Settings';
import { Context } from '../lib/context';
import type { WorkspaceContext } from '../lib/context';

vi.mock('@ionic/react', () => ({
  IonButton: ({ children, ...props }: React.ButtonHTMLAttributes<HTMLButtonElement>) => (
    <button {...props}>{children}</button>
  ),
  IonIcon: () => null,
  IonSpinner: () => null,
}));
afterEach(() => vi.unstubAllGlobals());

it('lets the default zero in a cash amount be cleared before typing', async () => {
  vi.stubGlobal(
    'fetch',
    vi.fn(
      async () =>
        new Response(
          JSON.stringify({
            data: {
              accounts: [{ id: '1', name: 'Cash', type: 'cash' }],
              items: [],
              contacts: [],
              categories: [],
            },
          }),
        ),
    ),
  );
  const context = {
    base: '/api/app/shop',
    path: '/app/shop',
    today: '20830715',
    revision: 0,
    changed: () => {},
    t: (s: string) => s,
    business: { role: 'owner', opening_finalized_at: null },
  } as unknown as WorkspaceContext;
  render(
    <MemoryRouter>
      <Context.Provider value={context}>
        <Opening />
      </Context.Provider>
    </MemoryRouter>,
  );
  const cash = await waitFor(() => screen.getByRole('textbox', { name: 'Cash (NPR)' }));
  expect(cash).toHaveValue('0');
  fireEvent.change(cash, { target: { value: '' } });
  expect(cash).toHaveValue('');
  fireEvent.change(cash, { target: { value: '500' } });
  expect(cash).toHaveValue('500');
});
