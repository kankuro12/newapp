import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { Context } from '../lib/context';
import type { Business } from '../lib/types';

const state=vi.hoisted(()=>({request:vi.fn(),retry:vi.fn(),uncertain:false,booking:{id:'4',resource_id:'1',resource_name:'Chair',version:2,client_name:'QA Client',business_date_bs:20830103,start_minute:600,end_minute:630,status:'arrived',document_id:undefined as string|undefined,services:[]}}));
vi.mock('../lib/api',()=>({ApiError:class extends Error{},useOnline:()=>true,request:state.request,useSave:()=>({busy:false,uncertain:state.uncertain,save:vi.fn(),retry:state.retry}),useLiveData:()=>({data:{data:[state.booking]}}),useData:(path:string)=>({loading:false,reload:vi.fn(),data:path.endsWith('/pos/config')?{data:{units:{unit:['count','1','1']},methods:['quantity'],resources:[{id:'1',kind:'staff',name:'Chair',start_minute:540,end_minute:1200,active:true}]}}:path.includes('/basket-offers')?{data:[{id:'7',name:'Save ten',discount_mode:'fixed',discount_value:'1000',minimum_spend_paisa:'0',enabled:true}]}:{data:{accounts:[{id:'1',name:'Cash'}]}}})}));
vi.mock('@ionic/react',()=>({IonButton:({children,type='button',...props}:React.ButtonHTMLAttributes<HTMLButtonElement>)=><button type={type} {...props}>{children}</button>,IonIcon:()=>null,IonSpinner:()=>null}));
async function checkout(version=2,locked=false,retry=vi.fn(),onCheckout=vi.fn()){
  const module=await import('./Pos');expect(module).toHaveProperty('ReviewedCheckout');
  const {ReviewedCheckout}=module;
  const view=<MemoryRouter><Context.Provider value={{base:'/api/app/shop',path:'/app/shop',business:{id:'1',role:'owner'} as Business,today:20830103,revision:0,changed:()=>{},t:s=>s,locale:'en'}}><ReviewedCheckout endpoint="/api/app/shop/appointments/4/checkout" context={{version,business_date_bs:20830103}} busy={false} disabled={false} uncertain={locked} retry={retry} onCheckout={onCheckout}/></Context.Provider></MemoryRouter>;
  return {view,onCheckout,render:()=>render(view)};
}
const proof=(fingerprint:string,total='10000')=>({data:{lines:[{item_id:'5',description:'Work',qty_milli:'1000',before_offer_paisa:'10000',total_paisa:total}],total_paisa:total,tax_paisa:'0',fingerprint}});
it('invalidates previous review on offer/source change and submits exact fresh proof',async()=>{
  const resolve:Array<(value:unknown)=>void>=[];state.request.mockReset().mockImplementation(()=>new Promise(done=>resolve.push(done)));
  const first=await checkout();const mounted=first.render();
  expect(screen.getByRole('button',{name:'Save bill + payment'})).toBeDisabled();
  await waitFor(()=>expect(resolve).toHaveLength(1));resolve[0](proof('a'.repeat(64)));
  await waitFor(()=>expect(screen.getByRole('button',{name:'Save bill + payment'})).toBeEnabled());
  fireEvent.change(screen.getByLabelText('Basket offer (optional)'),{target:{value:'7'}});
  expect(screen.getByRole('button',{name:'Save bill + payment'})).toBeDisabled();
  await waitFor(()=>expect(resolve).toHaveLength(2));expect(JSON.parse(state.request.mock.calls[1][1].body)).toMatchObject({version:2,business_date_bs:20830103,basket_offer_id:'7'});
  const changed=await checkout(3,false,vi.fn(),first.onCheckout);mounted.rerender(changed.view);
  expect(screen.getByRole('button',{name:'Save bill + payment'})).toBeDisabled();
  await waitFor(()=>expect(resolve).toHaveLength(3));resolve[1](proof('b'.repeat(64),'9000'));
  expect(screen.getByRole('button',{name:'Save bill + payment'})).toBeDisabled();
  resolve[2]({...proof('c'.repeat(64),'9000'),data:{...proof('c'.repeat(64),'9000').data,basket_offer:{name:'Save ten',discount_paisa:'1000'}}});
  await waitFor(()=>expect(screen.getByRole('button',{name:'Save bill + payment'})).toBeEnabled());
  fireEvent.click(screen.getByRole('button',{name:'Save bill + payment'}));
  expect(first.onCheckout).toHaveBeenCalledWith(expect.objectContaining({version:3,business_date_bs:20830103,basket_offer_id:'7',expected_fingerprint:'c'.repeat(64),expected_total_paisa:'9000',paid_now:'90.00',money_account_id:'1'}));
});
it('keeps selection after failed preview and requires a successful reviewed retry',async()=>{
  state.request.mockReset().mockResolvedValueOnce(proof('a'.repeat(64))).mockRejectedValueOnce(new Error('Offer unavailable')).mockResolvedValueOnce(proof('b'.repeat(64),'9000'));
  const form=await checkout();form.render();
  await waitFor(()=>expect(screen.getByRole('button',{name:'Save bill + payment'})).toBeEnabled());
  fireEvent.change(screen.getByLabelText('Basket offer (optional)'),{target:{value:'7'}});
  await waitFor(()=>expect(screen.getByRole('alert')).toHaveTextContent('Offer unavailable'));
  expect(screen.getByLabelText('Basket offer (optional)')).toHaveValue('7');
  expect(screen.getByRole('button',{name:'Save bill + payment'})).toBeDisabled();
  fireEvent.click(screen.getByRole('button',{name:'Check total again'}));
  await waitFor(()=>expect(screen.getByRole('button',{name:'Save bill + payment'})).toBeEnabled());
});
it('locks offer and payment entry after uncertain save and retries original action',async()=>{
  state.request.mockReset().mockResolvedValue(proof('a'.repeat(64)));const retry=vi.fn();
  const form=await checkout(2,true,retry);form.render();
  expect(screen.getByLabelText('Basket offer (optional)')).toBeDisabled();
  expect(screen.getByLabelText('Partial payment / pay later')).toBeDisabled();
  expect(screen.getByRole('button',{name:'Save bill + payment'})).toBeDisabled();
  fireEvent.click(screen.getByRole('button',{name:'Retry original action'}));expect(retry).toHaveBeenCalledOnce();
});
it('retains original retry when live appointment becomes billed after a lost checkout reply',async()=>{
  const {default:Pos}=await import('./Pos');state.request.mockReset().mockResolvedValue(proof('a'.repeat(64)));state.uncertain=false;state.retry.mockReset();state.booking.status='arrived';state.booking.document_id=undefined;
  const view=(revision:number)=><MemoryRouter><Context.Provider value={{base:'/api/app/shop',path:'/app/shop',business:{id:'1',name:'Shop',role:'owner',pos_profile:'salon'} as Business,today:20830103,revision,changed:()=>{},t:s=>s,locale:'en'}}><Pos/></Context.Provider></MemoryRouter>;
  const mounted=render(view(0));fireEvent.click(screen.getByRole('button',{name:/10:00–10:30 · QA Client/}));
  expect(screen.getByRole('group',{name:'Review & pay'})).toBeInTheDocument();
  state.uncertain=true;state.booking={...state.booking,status:'completed',document_id:'9',version:3};mounted.rerender(view(1));
  expect(screen.queryByRole('group',{name:'Review & pay'})).not.toBeInTheDocument();expect(screen.getByRole('link',{name:'Open bill'})).toHaveAttribute('href','/app/shop/document/9');
  fireEvent.click(screen.getByRole('button',{name:'Retry original action'}));expect(state.retry).toHaveBeenCalledOnce();
});
