import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { Context } from '../lib/context';
import type { Business } from '../lib/types';

const state=vi.hoisted(()=>({save:vi.fn(),uncertain:false,retry:vi.fn(),detail:undefined as object|undefined}));
vi.mock('../lib/api',()=>({ApiError:class extends Error{},useOnline:()=>true,useData:()=>({loading:false,data:state.detail?{data:state.detail}:undefined,reload:vi.fn()}),useSave:()=>({busy:false,error:undefined,uncertain:state.uncertain,save:state.save,retry:state.retry})}));
vi.mock('@ionic/react',()=>({IonButton:({children,type='button',...props}:React.ButtonHTMLAttributes<HTMLButtonElement>)=><button type={type} {...props}>{children}</button>,IonIcon:()=>null,IonSpinner:()=>null}));
vi.mock('../components/Picker',()=>({default:({kind,onPick}:{kind:string;onPick:(row:object)=>void})=>kind==='item_categories'?<button type="button" onClick={()=>onPick({id:'8',name:'Goods'})}>Choose Goods</button>:<><button type="button" onClick={()=>onPick({id:'5',name:'Work',unit_label:'job',pos_unit:'unit',kind:'service'})}>Choose Work</button><button type="button" onClick={()=>onPick({id:'6',name:'Gift',unit_label:'bottle',pos_unit:'unit',kind:'stock'})}>Choose Gift</button></>}));
async function editor(id='new'){
  const modulePromise=import('./BasketOffers');
  await expect(modulePromise).resolves.toHaveProperty('BasketOfferEditor');
  const {BasketOfferEditor}=await modulePromise;
  return render(<MemoryRouter initialEntries={['/app/shop/basket-offers/'+id]}><Context.Provider value={{base:'/api/app/shop',path:'/app/shop',business:{id:'1',role:'owner'} as Business,today:20830103,revision:0,changed:()=>{},t:s=>s,locale:'en'}}><Routes><Route path="/app/shop/basket-offers/new" element={<BasketOfferEditor/>}/><Route path="/app/shop/basket-offers/:id" element={<BasketOfferEditor/>}/></Routes></Context.Provider></MemoryRouter>);
}
it('saves exact spend, percentage cap and BS validity without resetting failed entry',async()=>{
  state.detail=undefined;state.uncertain=false;state.save.mockClear();await editor();
  fireEvent.change(screen.getByLabelText('Offer name'),{target:{value:'Festive basket'}});
  fireEvent.change(screen.getByLabelText('Discount type'),{target:{value:'percent'}});
  fireEvent.change(screen.getByLabelText('Discount (%)'),{target:{value:'12.50'}});
  fireEvent.change(screen.getByLabelText('Minimum spend (NPR)'),{target:{value:'200.01'}});
  fireEvent.change(screen.getByLabelText('Maximum saving (NPR, optional)'),{target:{value:'20.03'}});
  fireEvent.change(screen.getByLabelText('Starts (BS, optional)'),{target:{value:'2083-01-03'}});
  fireEvent.click(screen.getByRole('button',{name:'Save basket offer'}));
  await waitFor(()=>expect(state.save).toHaveBeenCalledOnce());
  expect(state.save.mock.calls[0][1]).toMatchObject({discount_mode:'percent',discount_value:'12.50',minimum_spend:'200.01',maximum_discount:'20.03',starts_bs:'2083-01-03',ends_bs:null});
  expect(screen.getByLabelText('Offer name')).toHaveValue('Festive basket');
});
it('preserves edit version and blocks uncertain edits with original retry',async()=>{
  state.detail={id:'9',name:'Original',discount_mode:'fixed',discount_value:'3001',minimum_spend_paisa:'20000',maximum_discount_paisa:null,enabled:true,cashier_allowed:false,version:4};state.uncertain=true;state.retry.mockClear();await editor('9');
  expect(screen.getByLabelText('Offer name')).toBeDisabled();
  expect(screen.getByRole('button',{name:'Save basket offer'})).toBeDisabled();
  fireEvent.click(screen.getByRole('button',{name:'Retry original action'}));expect(state.retry).toHaveBeenCalledOnce();state.uncertain=false;
});
it('sends reviewed bundle components in base units with exact price and cap',async()=>{
  state.detail=undefined;state.uncertain=false;state.save.mockClear();await editor();
  fireEvent.change(screen.getByLabelText('Offer name'),{target:{value:'Work and gift set'}});
  fireEvent.change(screen.getByLabelText('Offer type'),{target:{value:'bundle'}});
  fireEvent.change(screen.getByLabelText('Price per bundle (NPR)'),{target:{value:'200.01'}});
  fireEvent.click(screen.getByRole('button',{name:'Choose Work'}));fireEvent.click(screen.getByRole('button',{name:'Choose Gift'}));
  fireEvent.change(screen.getByLabelText('Required quantity (job)'),{target:{value:'2.500'}});
  fireEvent.change(screen.getByLabelText('Maximum applications (optional)'),{target:{value:'2'}});
  fireEvent.click(screen.getByRole('button',{name:'Save basket offer'}));await waitFor(()=>expect(state.save).toHaveBeenCalledOnce());
  expect(state.save.mock.calls[0][1]).toMatchObject({offer_kind:'bundle',discount_mode:'fixed',discount_value:'200.01',maximum_applications:2,rules:[{item_id:'5',role:'component',qty:'2.500',unit_snapshot:'job',pos_unit:'unit',item_kind:'service'},{item_id:'6',role:'component',qty:'1',unit_snapshot:'bottle',pos_unit:'unit',item_kind:'stock'}]});
});
it('keeps buy and reward rules distinct, accepts free rewards and rejects excessive percentages',async()=>{
  state.detail=undefined;state.uncertain=false;state.save.mockClear();await editor();
  fireEvent.change(screen.getByLabelText('Offer name'),{target:{value:'Free gift'}});
  fireEvent.change(screen.getByLabelText('Offer type'),{target:{value:'buy_get'}});
  fireEvent.click(within(screen.getByRole('region',{name:'Buy item'})).getByRole('button',{name:'Choose Work'}));
  fireEvent.click(within(screen.getByRole('region',{name:'Reward item'})).getByRole('button',{name:'Choose Gift'}));
  fireEvent.change(screen.getByLabelText('Reward discount (%)'),{target:{value:'100.01'}});
  fireEvent.click(screen.getByRole('button',{name:'Save basket offer'}));expect(state.save).not.toHaveBeenCalled();
  fireEvent.change(screen.getByLabelText('Reward discount (%)'),{target:{value:'100'}});
  fireEvent.click(screen.getByRole('button',{name:'Save basket offer'}));await waitFor(()=>expect(state.save).toHaveBeenCalledOnce());
  expect(state.save.mock.calls[0][1]).toMatchObject({offer_kind:'buy_get',discount_mode:'percent',discount_value:'100',rules:[{item_id:'5',role:'buy',qty:'1'},{item_id:'6',role:'get',qty:'1'}]});
});
it('saves selected item/category union and overnight Nepal-time schedule',async()=>{
  state.detail=undefined;state.uncertain=false;state.save.mockClear();await editor();
  fireEvent.change(screen.getByLabelText('Offer name'),{target:{value:'Evening goods'}});
  fireEvent.change(screen.getByLabelText('Offer type'),{target:{value:'items'}});
  fireEvent.change(screen.getByLabelText('Discount type'),{target:{value:'percent'}});
  fireEvent.change(screen.getByLabelText('Discount (%)'),{target:{value:'100'}});
  fireEvent.click(screen.getByRole('button',{name:'Choose Gift'}));fireEvent.click(screen.getByRole('button',{name:'Choose Goods'}));
  fireEvent.change(screen.getByLabelText('Starts at (Nepal time, optional)'),{target:{value:'22:00'}});
  fireEvent.change(screen.getByLabelText('Ends at (Nepal time, optional)'),{target:{value:'02:00'}});
  fireEvent.click(screen.getByLabelText('Thursday'));
  fireEvent.click(screen.getByRole('button',{name:'Save basket offer'}));await waitFor(()=>expect(state.save).toHaveBeenCalledOnce());
  expect(state.save.mock.calls[0][1]).toMatchObject({offer_kind:'items',discount_mode:'percent',discount_value:'100',rules:[{role:'target',item_ids:['6'],category_ids:['8']}],starts_time:'22:00',ends_time:'02:00',weekdays:[4],maximum_applications:null});
});
it('requires both schedule endpoints and preserves selected configuration after validation failure',async()=>{
  state.detail=undefined;state.uncertain=false;state.save.mockClear();await editor();
  fireEvent.change(screen.getByLabelText('Offer name'),{target:{value:'Unfinished schedule'}});
  fireEvent.change(screen.getByLabelText('Saving (NPR)'),{target:{value:'10'}});
  fireEvent.change(screen.getByLabelText('Starts at (Nepal time, optional)'),{target:{value:'17:00'}});
  fireEvent.click(screen.getByRole('button',{name:'Save basket offer'}));expect(state.save).not.toHaveBeenCalled();
  expect(screen.getByLabelText('Starts at (Nepal time, optional)')).toHaveValue('17:00');
  expect(screen.getByRole('alert')).toHaveTextContent('Enter both different start and end times.');
});
it('saves a unit-scoped choice group alongside a single item without losing member snapshots',async()=>{
  state.detail=undefined;state.uncertain=false;state.save.mockClear();await editor();
  fireEvent.change(screen.getByLabelText('Offer name'),{target:{value:'Any goods plus work'}});
  fireEvent.change(screen.getByLabelText('Offer type'),{target:{value:'bundle'}});
  fireEvent.change(screen.getByLabelText('Price per bundle (NPR)'),{target:{value:'100.01'}});
  fireEvent.click(screen.getByRole('button',{name:'Add choice group'}));
  const group=screen.getByRole('region',{name:'Choice group 1'});
  fireEvent.click(within(group).getByRole('button',{name:'Choose Gift'}));
  fireEvent.click(within(group).getByRole('button',{name:'Choose Goods'}));
  fireEvent.change(within(group).getByLabelText('Required quantity (unit)'),{target:{value:'1.501'}});
  fireEvent.click(screen.getAllByRole('button',{name:'Choose Work'}).find(button=>!group.contains(button))!);
  fireEvent.click(screen.getByRole('button',{name:'Save basket offer'}));await waitFor(()=>expect(state.save).toHaveBeenCalledOnce());
  expect(state.save.mock.calls[0][1]).toMatchObject({rules:[{role:'component',item_ids:['6'],category_ids:['8'],pos_unit:'unit',qty:'1.501',item_snapshots:[{item_id:'6',unit_snapshot:'bottle',pos_unit:'unit',item_kind:'stock'}]},{role:'component',item_id:'5',qty:'1'}]});
});
it('hydrates choice groups and blocks empty groups before submission',async()=>{
  state.detail={id:'9',name:'Any gift',offer_kind:'buy_get',discount_mode:'percent',discount_value:'10000',minimum_spend_paisa:'0',maximum_discount_paisa:null,enabled:true,cashier_allowed:true,version:4,rules:[{role:'buy',item_ids:['5'],item_names:['Work'],category_ids:['8'],category_names:['Goods'],qty_milli:'1501',unit_snapshot:'unit',pos_unit:'unit',item_snapshots:[{item_id:'5',unit_snapshot:'job',pos_unit:'unit',item_kind:'service'}]},{role:'get',item_id:'6',item_name:'Gift',qty_milli:'1000',unit_snapshot:'bottle',pos_unit:'unit',item_kind:'stock'}]};
  state.uncertain=false;state.save.mockClear();await editor('9');
  const group=screen.getByRole('region',{name:'Choice group 1'});
  expect(within(group).getByLabelText('Required quantity (unit)')).toHaveValue('1.501');
  for(const button of within(group).getAllByRole('button',{name:'Remove selection'}))fireEvent.click(button);
  fireEvent.click(screen.getByRole('button',{name:'Save basket offer'}));expect(state.save).not.toHaveBeenCalled();
  expect(screen.getByRole('alert')).toHaveTextContent('Choose at least one item or category.');
});
it('keeps unavailable selected offers visible for review and permits removal',async()=>{
  state.detail=[{id:'9',name:'Evening goods',enabled:true,discount_mode:'percent',discount_value:'1000',minimum_spend_paisa:'0',starts_minute:1320,ends_minute:120,weekdays:[4],available_now:false,rules:[{role:'target',item_ids:['6'],item_names:['Gift'],category_ids:['8'],category_names:['Goods']}]}];
  const {BasketOfferSelect}=await import('./BasketOffers');const change=vi.fn();
  render(<MemoryRouter><Context.Provider value={{base:'/api/app/shop',path:'/app/shop',business:{id:'1',role:'owner'} as Business,today:20830103,revision:0,changed:()=>{},t:s=>s,locale:'en'}}><BasketOfferSelect value="9" onChange={change}/></Context.Provider></MemoryRouter>);
  expect(screen.getByRole('option',{name:/Evening goods/})).toBeDisabled();
  expect(screen.getByText(/Offer unavailable now; remove or refresh availability./)).toBeInTheDocument();
  expect(screen.getByRole('button',{name:'Refresh offer availability'})).toBeEnabled();
  fireEvent.change(screen.getByLabelText('Basket offer (optional)'),{target:{value:''}});expect(change).toHaveBeenCalledWith('');
});
