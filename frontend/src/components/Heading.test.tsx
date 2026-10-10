import { fireEvent, render, screen, within } from '@testing-library/react';
import { Link, MemoryRouter, useLocation } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { Heading } from './ui';

vi.mock('@ionic/react', () => ({
  IonIcon: () => <span aria-hidden="true">←</span>,
  IonButton: ({ children }: { children: React.ReactNode }) => <button>{children}</button>,
  IonSpinner: () => null,
}));
afterEach(() => vi.restoreAllMocks());
function Location() {
  return <output aria-label="Current route">{useLocation().pathname}</output>;
}
function page({
  label = 'Back',
  guard = false,
  slot = true,
}: { label?: string; guard?: boolean; slot?: boolean } = {}) {
  return (
    <MemoryRouter initialEntries={['/stock/count']}>
      <header className="topbar">
        {slot && <span className="mobile-back-slot" />}
        <span className="mobile-logo">b</span>
      </header>
      <Heading title="Count stock">
        <button type="button">Count history</button>
        <Link
          to="/items"
          onClick={(event) => {
            if (guard) event.preventDefault();
          }}
        >
          {label}
        </Link>
      </Heading>
      <Location />
    </MemoryRouter>
  );
}
function mobile(matches = true) {
  vi.spyOn(window, 'matchMedia').mockReturnValue({
    matches,
    addEventListener: () => {},
    removeEventListener: () => {},
  } as unknown as MediaQueryList);
}

it.each(['Back', 'पछाडि'])(
  'moves %s before mobile logo and keeps original destination',
  (label) => {
    mobile();
    const view = render(page({ label }));
    const back = within(view.container.querySelector('.mobile-back-slot') as HTMLElement).getByRole(
      'link',
      { name: label },
    );
    expect(view.container.querySelector('.heading-actions')).not.toContainElement(back);
    expect(back.compareDocumentPosition(view.container.querySelector('.mobile-logo')!)).toBe(
      Node.DOCUMENT_POSITION_FOLLOWING,
    );
    expect(screen.getByRole('button', { name: 'Count history' })).toBeInTheDocument();
    fireEvent.click(back);
    expect(screen.getByLabelText('Current route')).toHaveTextContent('/items');
  },
);

it('preserves blocked Back handler after moving link', () => {
  mobile();
  const view = render(page({ guard: true }));
  fireEvent.click(
    within(view.container.querySelector('.mobile-back-slot') as HTMLElement).getByRole('link', {
      name: 'Back',
    }),
  );
  expect(screen.getByLabelText('Current route')).toHaveTextContent('/stock/count');
});

it('keeps desktop Back in page actions', () => {
  mobile(false);
  const view = render(page());
  expect(
    within(view.container.querySelector('.heading-actions') as HTMLElement).getByRole('link', {
      name: 'Back',
    }),
  ).toBeInTheDocument();
  expect(view.container.querySelector('.mobile-back-slot')).toBeEmptyDOMElement();
});

it('retains Back when no workspace top bar exists', () => {
  mobile();
  const view = render(page({ slot: false }));
  expect(
    within(view.container.querySelector('.heading-actions') as HTMLElement).getByRole('link', {
      name: 'Back',
    }),
  ).toBeInTheDocument();
});
