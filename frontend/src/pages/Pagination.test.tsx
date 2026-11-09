import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { Pagination } from './Records';

vi.mock('@ionic/react', () => ({
  IonButton: ({ children, ...props }: React.ButtonHTMLAttributes<HTMLButtonElement>) => (
    <button {...props}>{children}</button>
  ),
  IonIcon: () => null,
  IonSpinner: () => null,
}));

// Platform screens render Pagination with no business context; it must not throw.
it('renders outside a business with English labels', () => {
  render(<Pagination page={1} pages={3} change={() => undefined} />);
  expect(screen.getByText('Next')).toBeTruthy();
  expect(screen.getByText('1 / 3')).toBeTruthy();
});
