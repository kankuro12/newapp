import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { Context } from '../lib/context';
import type { Business, Party } from '../lib/types';
import RegularPayments from './RegularPayments';
import { ApiError } from '../lib/api';

const state = vi.hoisted(()=>({busy:false,error:undefined as Error|undefined,save:vi.fn()}));
vi.mock('../lib/api',()=>({ApiError:class extends Error {status:number;errors:Record<string,string[]>;constructor(status:number,message:string,errors:Record<string,string[]>={}){super(message);this.status=status;this.errors=errors;}},useOnline:()=>true,useSave:()=>state,useData:(url:string|null)=>({loading:false,reload:()=>{},data:{data:url?.endsWith('/lookup')?{accounts:[],categories:[{id:'3',name:'Power'}]}:url?.includes('/period?')?{status:'planned',total_paisa:'1000000',due_paisa:'1000000'}:url?.endsWith('/regular-expenses/1')?{id:'1',kind:'salary',payee_name:'Existing payee',label:'Monthly salary',amount_paisa:'1000000',next_date_bs:20830103,auto_generate:true,enabled:true,version:4,current_month:{document_id:null}}:url?.includes('/history?')?[]:[{id:'1',payee_name:'Existing payee',label:'Monthly salary',amount_paisa:'1000000',next_date_bs:20830103,enabled:true,current_month:{document_id:null}}],last_page:1}})}));
vi.mock('../components/Picker',()=>({default:({role,onPick}:{role:string;onPick:(party:Party)=>void})=><button type="button" onClick={()=>onPick({id:'9',name:'Hari',is_rent:true} as Party)}>Pick existing {role} payee</button>}));
vi.mock('@ionic/react',()=>({IonIcon:()=>null,IonSpinner:()=>null,IonButton:({children,type='button',...props}:React.ButtonHTMLAttributes<HTMLButtonElement>)=><button type={type} {...props}>{children}</button>}));
afterEach(()=>{state.error=undefined;state.save.mockClear();vi.restoreAllMocks();});
function page() {return <MemoryRouter><Context.Provider value={{base:'/api/app/shop',path:'/app/shop',business:{role:'owner'} as Business,today:20830103,revision:0,changed:()=>{},t:s=>s,locale:'en'}}><RegularPayments/></Context.Provider></MemoryRouter>;}
function mobile() {vi.spyOn(window,'matchMedia').mockReturnValue({matches:true,addEventListener:()=>{},removeEventListener:()=>{}} as unknown as MediaQueryList);}

it('keeps mobile salary setup focused and advances without posting until schedule confirmation',()=>{
  mobile();render(page());fireEvent.click(screen.getByRole('button',{name:'Add employee'}));
  expect(screen.queryByText('Existing payee')).not.toBeInTheDocument();
  expect(screen.getByRole('button',{name:'1 Payee'})).toHaveAttribute('aria-current','step');
  fireEvent.change(screen.getByLabelText('Employee name'),{target:{value:'Rajesh'}});
  fireEvent.keyDown(screen.getByLabelText('Employee name'),{key:'Enter'});
  expect(screen.getByRole('button',{name:'2 Amount'})).toHaveAttribute('aria-current','step');
  fireEvent.change(screen.getByLabelText('Monthly salary (NPR)'),{target:{value:'12500.25'}});
  fireEvent.click(screen.getByRole('button',{name:'Continue to Schedule'}));
  expect(state.save).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button',{name:'Save'}));
  expect(state.save).toHaveBeenCalledWith('/api/app/shop/regular-expenses',{kind:'salary',contact_id:null,payee_name:'Rajesh',label:'Monthly salary',amount:'12500.25',first_date_bs:'2083-01-03',monthly_day:'3',auto_generate:true,enabled:true},expect.any(Function),'POST');
});

it('retains existing rent payee and manual/paused options through steps',()=>{
  mobile();render(page());fireEvent.click(screen.getByRole('button',{name:'Rent'}));fireEvent.click(screen.getByRole('button',{name:'Add rent'}));
  fireEvent.click(screen.getByRole('checkbox',{name:'Choose existing party'}));fireEvent.click(screen.getByRole('button',{name:'Pick existing rent payee'}));
  fireEvent.click(screen.getByRole('button',{name:'Continue to Amount'}));fireEvent.change(screen.getByLabelText('Monthly rent (NPR)'),{target:{value:'15000'}});
  fireEvent.click(screen.getByRole('button',{name:'Continue to Schedule'}));
  fireEvent.click(screen.getByRole('checkbox',{name:'Automatically record monthly expense + payable'}));fireEvent.click(screen.getByRole('checkbox',{name:'Enabled'}));
  fireEvent.click(screen.getByRole('button',{name:'Save'}));
  expect(state.save.mock.calls[0][1]).toMatchObject({kind:'rent',contact_id:'9',payee_name:null,amount:'15000',auto_generate:false,enabled:false});
});

it('returns server amount validation to its hidden step while keeping entered payee',async()=>{
  mobile();const view=render(page());fireEvent.click(screen.getByRole('button',{name:'Add employee'}));fireEvent.change(screen.getByLabelText('Employee name'),{target:{value:'Rajesh'}});
  fireEvent.click(screen.getByRole('button',{name:'Continue to Amount'}));fireEvent.change(screen.getByLabelText('Monthly salary (NPR)'),{target:{value:'12500'}});fireEvent.click(screen.getByRole('button',{name:'Continue to Schedule'}));
  state.error=new ApiError(422,'Invalid amount',{amount:['Amount rejected']});view.rerender(page());
  await waitFor(()=>expect(screen.getByRole('button',{name:'2 Amount'})).toHaveAttribute('aria-current','step'));
  expect(screen.getByLabelText('Employee name')).toHaveValue('Rajesh');expect(state.save).not.toHaveBeenCalled();
});

it('keeps other regular-bill category in the original create payload',()=>{
  mobile();render(page());fireEvent.click(screen.getByRole('button',{name:'Other'}));fireEvent.click(screen.getByRole('button',{name:'Add regular bill'}));
  fireEvent.change(screen.getByLabelText('Payee name'),{target:{value:'Power office'}});fireEvent.click(screen.getByRole('button',{name:'Continue to Amount'}));
  expect(screen.getByLabelText('Category')).toHaveValue('3');fireEvent.change(screen.getByLabelText('Monthly amount (NPR)'),{target:{value:'1200.50'}});
  fireEvent.click(screen.getByRole('button',{name:'Continue to Schedule'}));fireEvent.click(screen.getByRole('button',{name:'Save'}));
  expect(state.save.mock.calls[0][1]).toMatchObject({kind:'other',payee_name:'Power office',expense_category_id:'3',amount:'1200.50',auto_generate:true});
});

it('edits amount and options with original version while retaining immutable party and schedule',()=>{
  mobile();render(page());fireEvent.click(screen.getByRole('button',{name:'Open'}));fireEvent.click(screen.getByRole('button',{name:'Edit / pause'}));
  expect(screen.queryByLabelText('Employee name')).not.toBeInTheDocument();expect(screen.queryByLabelText('First expense date (BS)')).not.toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('Monthly salary (NPR)'),{target:{value:'11000.25'}});fireEvent.click(screen.getByRole('button',{name:'Continue to Schedule'}));fireEvent.click(screen.getByRole('button',{name:'Save'}));
  expect(state.save).toHaveBeenCalledWith('/api/app/shop/regular-expenses/1',{version:4,label:'Monthly salary',amount:'11000.25',auto_generate:true,enabled:true},expect.any(Function),'PATCH');
});
