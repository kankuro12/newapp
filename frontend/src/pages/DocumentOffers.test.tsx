import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

const state = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('../lib/api', () => ({ request: state.request }));
vi.mock('@ionic/react', () => ({ IonButton: () => null, IonIcon: () => null, IonSpinner: () => null }));
const proof = (fingerprint: string, total = '9000') => ({ data: { total_paisa: total, tax_paisa: '0', subtotal_paisa: '10000', invoice_discount_paisa: '1000', line_discount_paisa: '0', lines: [], fingerprint, basket_offer: { id: '7', name: 'Save ten', discount_paisa: '1000' } } });

async function harness() {
  const { useReviewedOffer } = await import('../lib/useReviewedOffer');
  function Review({ qty = '1', version = 1, selected = true, locked = false }) {
    const review = useReviewedOffer('/documents/sale/preview', { lines: [{ qty }], basket_offer_id: selected ? '7' : null, version }, selected, locked);
    return <><output>{review.preview?.total_paisa || 'unreviewed'}</output>{review.error && <p role="alert">{review.error.message}</p>}<button disabled={!review.preview || locked}>Save</button><button onClick={review.recheck}>Review again</button></>;
  }
  return Review;
}

it('invalidates proof immediately and ignores old quantity or version replies', async () => {
  const resolve: Array<(value: unknown) => void> = [];
  state.request.mockReset().mockImplementation(() => new Promise(done => resolve.push(done)));
  const Review = await harness(); const view = render(<Review />);
  await waitFor(() => expect(resolve).toHaveLength(1)); resolve[0](proof('a'.repeat(64)));
  await waitFor(() => expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled());
  view.rerender(<Review qty="2" />);
  expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
  await waitFor(() => expect(resolve).toHaveLength(2));
  view.rerender(<Review qty="2" version={2} />);
  await waitFor(() => expect(resolve).toHaveLength(3)); resolve[1](proof('b'.repeat(64), '19000'));
  expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
  resolve[2](proof('c'.repeat(64), '19000'));
  await waitFor(() => expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled());
  expect(JSON.parse(state.request.mock.calls[2][1].body)).toMatchObject({ version: 2, lines: [{ qty: '2' }] });
});

it('failed preview stays blocked until explicit successful review', async () => {
  state.request.mockReset().mockRejectedValueOnce(new Error('Offer changed')).mockResolvedValueOnce(proof('d'.repeat(64)));
  const Review = await harness(); render(<Review />);
  await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Offer changed'));
  expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Review again' }));
  expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
  await waitFor(() => expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled());
});

it('does not request or retain an offer review after deselection or uncertain save', async () => {
  state.request.mockReset().mockResolvedValue(proof('a'.repeat(64)));
  const Review = await harness(); const view = render(<Review locked />);
  expect(state.request).not.toHaveBeenCalled(); expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
  view.rerender(<Review />);
  await waitFor(() => expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled());
  view.rerender(<Review selected={false} />);
  expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled(); expect(screen.getByText('unreviewed')).toBeInTheDocument();
});
