import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { HelpLink } from './Help';
import { manualSection, manualUrl } from '../lib/manual';

vi.mock('@ionic/react', () => ({ IonIcon: () => null }));

it('maps workspace screens to their manual sections', () => {
  expect(manualSection('/gym')).toBe('gym');
  expect(manualSection('/pos/setup')).toBe('pos');
  expect(manualSection('/document/12/return')).toBe('corrections');
  expect(manualSection('/sales')).toBe('bills');
  expect(manualSection('/collections')).toBe('payments');
  expect(manualSection('/unknown')).toBe('contents');
  expect(manualUrl('gym')).toBe('/help/user-manual.html#gym');
});

it('opens the section inside the workspace or the full manual in a new tab', () => {
  render(
    <MemoryRouter>
      <HelpLink path="/app/shop" section="gym" />
      <HelpLink section="packages" label="Packages help" />
    </MemoryRouter>,
  );
  expect(screen.getByRole('link', { name: 'Help' })).toHaveAttribute(
    'href',
    '/app/shop/help?section=gym',
  );
  const full = screen.getByRole('link', { name: 'Packages help' });
  expect(full).toHaveAttribute('href', '/help/user-manual.html#packages');
  expect(full).toHaveAttribute('target', '_blank');
});
