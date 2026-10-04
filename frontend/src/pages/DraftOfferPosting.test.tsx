import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { Context } from '../lib/context';
import type { Business, Doc } from '../lib/types';

const state = vi.hoisted(() => ({ request: vi.fn(), saved: vi.fn(), uncertain: false }));
vi.mock('../lib/api', () => ({ ApiError: class extends Error {}, request: state.request, useOnline: () => true }));
vi.mock('@ionic/react', () => ({ IonButton: ({ children, type = 'button', ...props }: React.ButtonHTMLAttributes<HTMLButtonElement>) => <button type={type} {...props}>{children}</button>, IonIcon: () => null, IonSpinner: () => null }));
async function view(version = 1, opening = true) {
  const module = await import('./DocumentOffers'); expect(module).toHaveProperty('DraftPosting');
  const { DraftPosting } = module;
  const doc = { id: '4', type: 'sale', version, total_paisa: '9000', basket_offer_id: '7' } as Doc;
  return <MemoryRouter><Context.Provider value={{ base: '/api/app/shop', path: '/app/shop', business: { id: '1', role: 'owner', opening_finalized_at: opening ? 'yes' : null } as Business, today: 20830103, revision: 0, changed: () => {}, t: s => s, locale: 'en' }}><DraftPosting doc={doc} accounts={[{ id: '1', name: 'Cash' }]} form={{ busy: false, uncertain: state.uncertain, save: state.saved } as never} onSaved={() => {}} /></Context.Provider></MemoryRouter>;
}
const proof = { data: { total_paisa: '9000', tax_paisa: '0', fingerprint: 'a'.repeat(64), basket_offer: { name: 'Save ten', discount_paisa: '1000' } } };
it('posts draft only with review of its current version and total', async () => {
  state.uncertain = false; state.saved.mockReset(); state.request.mockReset().mockResolvedValueOnce(proof).mockImplementation(() => new Promise(() => {}));
  const mounted = render(await view()); expect(screen.getByRole('button', { name: 'Post bill' })).toBeDisabled();
  await waitFor(() => expect(screen.getByRole('button', { name: 'Post bill' })).toBeEnabled());
  fireEvent.click(screen.getByRole('button', { name: 'Post bill' }));
  expect(state.saved.mock.calls[0][1]).toMatchObject({ version: 1, expected_total_paisa: '9000', expected_fingerprint: 'a'.repeat(64) });
  mounted.rerender(await view(2)); expect(screen.getByRole('button', { name: 'Post bill' })).toBeDisabled();
});
it('changed or expired offer directs editing and blocks posting', async () => {
  state.uncertain = false; state.request.mockReset().mockRejectedValue(new Error('Draft offer changed. Edit and review.'));
  render(await view()); await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Draft offer changed'));
  expect(screen.getByRole('link', { name: 'Edit draft' })).toHaveAttribute('href', '/app/shop/documents/sale/new?draft=4');
  expect(screen.getByRole('button', { name: 'Post bill' })).toBeDisabled();
});
it('requires starting balances before posting a reviewed draft', async () => {
  state.uncertain=false; state.request.mockReset().mockResolvedValue(proof); render(await view(1,false));
  await waitFor(()=>expect(screen.getByText('Save ten')).toBeInTheDocument());
  expect(screen.getByRole('button',{name:'Post bill'})).toBeDisabled();
  expect(screen.getByRole('link',{name:'Complete starting balances to post.'})).toHaveAttribute('href','/app/shop/opening');
});
