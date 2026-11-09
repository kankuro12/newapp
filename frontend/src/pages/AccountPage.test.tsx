import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { useState } from 'react';
import { expect, it, vi } from 'vitest';
import { AccountPage } from './Auth';
import type { User } from '../lib/types';

vi.mock('../lib/api', () => ({
  send: vi.fn(async () => ({})),
  resetSession: vi.fn(),
  useOnline: () => true,
  ApiError: class extends Error {},
}));
vi.mock('../lib/push', () => ({ tenantSignOut: vi.fn() }));
vi.mock('@ionic/react', () => ({
  IonPage: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  IonContent: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  IonSpinner: () => null,
  IonIcon: () => null,
  IonButton: ({ children, ...props }: React.ButtonHTMLAttributes<HTMLButtonElement>) => (
    <button {...props}>{children}</button>
  ),
}));

const user = { id: '1', name: 'Owner', email: 'owner@example.com' } as User;

// Mirrors App: a session reload unmounts and remounts the account page.
function Remounting() {
  const [count, setCount] = useState(0);
  return <AccountPage key={count} user={user} reload={() => setCount((c) => c + 1)} />;
}

it('keeps the "Account updated." notice after the session reload remounts the page', async () => {
  render(
    <MemoryRouter>
      <Remounting />
    </MemoryRouter>,
  );
  fireEvent.submit(screen.getByDisplayValue('Owner').closest('form')!);
  expect(await screen.findByText('Account updated.')).toBeTruthy();
});
