import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import PlatformPackages from './PlatformPackages';
const state = vi.hoisted(() => ({
  save: vi.fn(),
  retry: vi.fn(),
  busy: false,
  uncertain: false,
  error: undefined,
}));
vi.mock('../lib/api', () => ({
  ApiError: class extends Error {},
  useOnline: () => true,
  useSave: () => state,
  useData: () => ({ loading: false, reload: vi.fn(), data: { data: [] } }),
}));
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
afterEach(() => {
  state.uncertain = false;
  vi.clearAllMocks();
});
it('submits exact minor-unit prices with guard password and version', () => {
  const view = render(
    <MemoryRouter>
      <PlatformPackages />
    </MemoryRouter>,
  );
  view.container.querySelector('details')!.open = true;
  fireEvent.click(screen.getByRole('button', { name: 'New package' }));
  fireEvent.change(screen.getByLabelText('Package name'), { target: { value: 'Gym access' } });
  fireEvent.change(screen.getByLabelText('NPR price'), { target: { value: '123.29' } });
  fireEvent.change(screen.getByLabelText('Change reason'), { target: { value: 'New pricing' } });
  fireEvent.change(screen.getByLabelText('Confirm admin password'), {
    target: { value: 'test-password' },
  });
  fireEvent.submit(screen.getByRole('button', { name: 'Save package' }).closest('form')!);
  expect(state.save.mock.calls[0]).toEqual([
    '/api/platform/packages',
    expect.objectContaining({
      version: 1,
      password: 'test-password',
      prices: [{ currency: 'NPR', amount_minor: '12329' }],
    }),
    expect.any(Function),
    'POST',
  ]);
});
it('locks catalogue mutations while original save outcome is uncertain', () => {
  state.uncertain = true;
  const view = render(
    <MemoryRouter>
      <PlatformPackages />
    </MemoryRouter>,
  );
  view.container.querySelector('details')!.open = true;
  expect(screen.getByRole('button', { name: 'New package' })).toBeDisabled();
});
