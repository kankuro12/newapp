import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { useState } from 'react';
import EntrySteps from './EntrySteps';
import { Field } from './ui';

afterEach(() => vi.restoreAllMocks());
function fixture(mobile = true) {
  vi.spyOn(window, 'matchMedia').mockReturnValue({
    matches: mobile,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  } as unknown as MediaQueryList);
  const post = vi.fn();
  function Form() {
    const [name, setName] = useState('');
    return (
      <EntrySteps labels={['Party', 'Items', 'Review']} onSubmit={post}>
        <section data-entry-step="0">
          <h2>Party details</h2>
          <Field label="Name" required value={name} onChange={(e) => setName(e.target.value)} />
        </section>
        <section data-entry-step="1">
          <h2>Item details</h2>
          <Field label="Quantity" defaultValue="1" required />
        </section>
        <section data-entry-step="2">
          <h2>Review entry</h2>
          <details>
            <summary>Optional details</summary>
            <Field label="Email" type="email" defaultValue="bad-email" />
          </details>
          <button type="submit">Post sale</button>
        </section>
      </EntrySteps>
    );
  }
  render(<Form />);
  return post;
}

test('mobile Continue and Enter advance without posting and retain earlier values', () => {
  const post = fixture();
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Items' }));
  expect(screen.getByRole('button', { name: '1 Party' })).toHaveAttribute('aria-current', 'step');
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Ram' } });
  fireEvent.keyDown(screen.getByLabelText('Name'), { key: 'Enter' });
  expect(screen.getByRole('button', { name: '2 Items' })).toHaveAttribute('aria-current', 'step');
  fireEvent.click(screen.getByRole('button', { name: 'Continue to Review' }));
  expect(post).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button', { name: '1 Party' }));
  expect(screen.getByLabelText('Name')).toHaveValue('Ram');
  fireEvent.submit(screen.getByLabelText('Name').closest('form')!);
  expect(post).not.toHaveBeenCalled();
});

test('invalid field opens its optional disclosure and reveals its mobile step', () => {
  fixture();
  const email = screen.getByLabelText('Email');
  fireEvent.invalid(email);
  expect(email.closest('details')).toHaveAttribute('open');
  expect(screen.getByRole('button', { name: '3 Review' })).toHaveAttribute('aria-current', 'step');
});

test('desktop submission retains existing single-page behaviour', () => {
  const post = fixture(false);
  fireEvent.submit(screen.getByLabelText('Name').closest('form')!);
  expect(post).toHaveBeenCalledTimes(1);
});
