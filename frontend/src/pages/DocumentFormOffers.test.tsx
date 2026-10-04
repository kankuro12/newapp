import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import DocumentForm from './DocumentForm';
import { Context } from '../lib/context';
import type { Business } from '../lib/types';

const state = vi.hoisted(() => ({ request: vi.fn(), saved: vi.fn(), retry: vi.fn(), uncertain: false, draft: undefined as object | undefined, workflow: undefined as object | undefined }));
vi.mock('../lib/api', async () => { const {useMemo}=await import('react'); return ({ ApiError: class extends Error {}, request: state.request, useOnline: () => true, useSave: () => ({ busy: false, uncertain: state.uncertain, save: state.saved, retry: state.retry }), useData: (path: string | null) => { const draft=state.draft; const workflow=state.workflow; return useMemo(() => ({ loading: false, reload: vi.fn(), data: !path ? undefined : path.includes('/document/') ? draft ? {data:draft} : undefined : path.includes('/workflow/') ? workflow ? {data:workflow} : undefined : path?.includes('/basket-offers') ? { data: [{ id: '7', name: 'Save ten', enabled: true, minimum_spend_paisa: '0', discount_mode: 'fixed', discount_value: '1000' }] } : path?.endsWith('/lookup') ? { data: { accounts: [{ id: '1', name: 'Cash' }], items: [], contacts: [], categories: [] } } : { data: [] } }),[path,draft,workflow]); } }); });
vi.mock('../components/Picker', () => ({ default: ({ kind, onPick }: { kind: string; onPick: (row: object) => void }) => kind === 'items' ? <button type="button" onClick={() => onPick({ id: '5', name: 'Work', kind: 'service', unit_label: 'job', sale_price_paisa: '10000', default_tax_bps: '0' })}>Add work</button> : null }));
vi.mock('@ionic/react', () => ({ IonButton: ({ children, type = 'button', ...props }: React.ButtonHTMLAttributes<HTMLButtonElement>) => <button type={type} {...props}>{children}</button>, IonIcon: () => null, IonSpinner: () => null }));
const proof = { data: { total_paisa: '9000', tax_paisa: '0', subtotal_paisa: '10000', invoice_discount_paisa: '1000', line_discount_paisa: '0', fingerprint: 'a'.repeat(64), basket_offer: { id: '7', name: 'Save ten', discount_paisa: '1000' }, lines: [{ gross_paisa: '10000', line_discount_paisa: '0', invoice_discount_paisa: '1000', net_base_paisa: '9000', tax_paisa: '0', total_paisa: '9000' }] } };
function form(search = '', workflowKind?: string) {
  return <MemoryRouter initialEntries={['/app/shop/documents/sale/new' + search]}><Context.Provider value={{ base: '/api/app/shop', path: '/app/shop', business: { id: '1', role: 'owner', opening_finalized_at: 'yes' } as Business, today: 20830103, revision: 0, changed: () => {}, t: s => s, locale: 'en' }}><Routes><Route path="/app/shop/documents/:type/new" element={<DocumentForm workflowKind={workflowKind}/>} /></Routes></Context.Provider></MemoryRouter>;
}
it('posts current reviewed total and payment; changed quantity blocks old proof', async () => {
  state.draft = undefined; state.uncertain = false; state.saved.mockReset(); state.request.mockReset().mockResolvedValueOnce(proof).mockImplementation(() => new Promise(() => {}));
  render(form()); fireEvent.click(screen.getByRole('button', { name: 'Add work' }));
  fireEvent.change(screen.getByLabelText('Basket offer (optional)'), { target: { value: '7' } });
  expect(screen.getByRole('button', { name: 'Post sale' })).toBeDisabled();
  await waitFor(() => expect(screen.getByRole('button', { name: 'Post sale' })).toBeEnabled());
  fireEvent.click(screen.getByRole('button', { name: 'Post sale' }));
  expect(state.saved.mock.calls[0][1]).toMatchObject({ basket_offer_id: '7', expected_fingerprint: 'a'.repeat(64), expected_total_paisa: '9000', paid_now: '90.00', invoice_discount: '0' });
  fireEvent.change(screen.getByLabelText('Quantity (job)'), { target: { value: '2' } });
  expect(screen.getByRole('button', { name: 'Post sale' })).toBeDisabled();
});
it('hydrates pre-offer draft inputs instead of applying saved saving twice', async () => {
  state.uncertain = false; state.saved.mockReset(); state.request.mockReset().mockResolvedValue(proof);
  state.draft = { id: '4', type: 'sale', status: 'draft', version: 2, business_date_bs: 20830103, party_snapshot: { id: '3', name: 'Buyer' }, invoice_discount_paisa: '1000', lines: [{ item_id: '5', description: 'Work', unit_snapshot: 'job', qty_milli: '1000', unit_price_paisa: '10000', line_discount_paisa: '1500', tax_bps: '0', tax_category: 'outside_scope' }], draft_input: { basket_offer_id: '7', invoice_discount: '0', lines: [{ qty: '1', unit_price: '100', discount: '5', tax_bps: '0', tax_category: 'outside_scope' }] } };
  render(form('?draft=4'));
  expect(screen.getByLabelText('Line discount (NPR)')).toHaveValue('5');
  expect(screen.getByLabelText('Bill discount (NPR)')).toHaveValue('0');
  expect(screen.getByLabelText('Basket offer (optional)')).toHaveValue('7');
  await waitFor(() => expect(screen.getByRole('button', { name: 'Save draft changes' })).toBeEnabled());
  fireEvent.click(screen.getByRole('button', { name: 'Save draft changes' }));
  expect(state.saved.mock.calls[0][1]).toMatchObject({ version: 2, basket_offer_id: '7', lines: [{ discount: '5' }], expected_fingerprint: 'a'.repeat(64), paid_now: '0.00' });
  state.draft = undefined;
});
it('locks entry during uncertain save and keeps original retry available', () => {
  state.draft = undefined; state.uncertain = true; state.retry.mockReset(); render(form());
  expect(screen.getByLabelText('Business date (BS)')).toBeDisabled();
  expect(screen.getByRole('button', { name: 'Post sale' })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Retry original action' })); expect(state.retry).toHaveBeenCalledOnce();
  state.uncertain = false;
});

it('saves a quote with current review without recording a payment', async () => {
  state.workflow=undefined; state.draft=undefined; state.uncertain=false; state.saved.mockReset(); state.request.mockReset().mockResolvedValue(proof);
  render(form('', 'quote')); fireEvent.click(screen.getByRole('button', {name:'Add work'}));
  fireEvent.change(screen.getByLabelText('Basket offer (optional)'), {target:{value:'7'}});
  await waitFor(()=>expect(screen.getByRole('button',{name:'Save quote'})).toBeEnabled());
  fireEvent.click(screen.getByRole('button',{name:'Save quote'}));
  expect(state.saved.mock.calls[0][0]).toBe('/api/app/shop/workflows');
  expect(state.saved.mock.calls[0][1]).toMatchObject({kind:'quote', basket_offer_id:'7', expected_fingerprint:'a'.repeat(64), expected_total_paisa:'9000'});
  expect(state.saved.mock.calls[0][1]).not.toHaveProperty('paid_now');
});

it('copying approved quote restores pre-offer prices and requires a new offer selection', () => {
  state.draft=undefined; state.uncertain=false; state.request.mockReset();
  state.workflow={id:'8',kind:'quote',status:'accepted',version:3,business_date_bs:20830102,niche:'glass',party_snapshot:{id:'3',name:'Buyer'},invoice_discount_paisa:'1000',lines:[{item_id:'5',description:'Work',unit_snapshot:'job',qty_milli:'1000',unit_price_paisa:'10000',line_discount_paisa:'1500',tax_bps:'0',tax_category:'outside_scope'}],bill_input:{basket_offer_id:'7',offer_input:{invoice_discount:'0',lines:[{qty:'1',unit_price:'100',discount:'5',tax_bps:'0',tax_category:'outside_scope'}]}}};
  render(form('?copy=8','quote'));
  expect(screen.getByLabelText('Line discount (NPR)')).toHaveValue('5');
  expect(screen.getByLabelText('Bill discount (NPR)')).toHaveValue('0');
  expect(screen.getByLabelText('Basket offer (optional)')).toHaveValue('');
  expect(state.request).not.toHaveBeenCalled(); state.workflow=undefined;
});
