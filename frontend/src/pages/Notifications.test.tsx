import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import Notifications from './Notifications';
const mocks = vi.hoisted(() => ({
  send: vi.fn().mockResolvedValue({}),
  enable: vi.fn().mockResolvedValue(undefined),
  revoke: vi.fn().mockResolvedValue(undefined),
}));
vi.mock('../lib/api', () => ({
  useData: (path: string) => ({
    loading: false,
    reload: vi.fn(),
    data: {
      data: path.endsWith('devices')
        ? [{ id: '12', name: 'Browser', active: true }]
        : {
            operational_email: true,
            promotional_email: false,
            operational_fcm: false,
            promotional_fcm: false,
            operational_whatsapp: false,
            promotional_whatsapp: false,
          },
    },
  }),
  send: mocks.send,
  request: vi.fn(),
}));
vi.mock('../lib/push', () => ({ enablePush: mocks.enable, revokeCurrentPush: mocks.revoke }));
vi.mock('@ionic/react', () => ({
  IonPage: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  IonContent: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  IonIcon: () => null,
  IonSpinner: () => null,
  IonButton: ({
    children,
    onClick,
    disabled,
  }: {
    children: React.ReactNode;
    onClick?: () => void;
    disabled?: boolean;
  }) => (
    <button onClick={onClick} disabled={disabled}>
      {children}
    </button>
  ),
}));
it('keeps promotions opt-in and enables push only on button click', async () => {
  render(
    <MemoryRouter>
      <Notifications userId="7" />
    </MemoryRouter>,
  );
  expect(screen.getByRole('checkbox', { name: 'Email promotions' })).not.toBeChecked();
  expect(mocks.enable).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button', { name: 'Enable push on this device' }));
  await waitFor(() => expect(mocks.enable).toHaveBeenCalledWith('7'));
  fireEvent.click(screen.getByRole('checkbox', { name: 'Email promotions' }));
  await waitFor(() =>
    expect(mocks.send).toHaveBeenCalledWith(
      '/api/notifications/preferences',
      { promotional_email: true },
      'PATCH',
    ),
  );
});
