import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { Context } from '../lib/context';
import type { Business } from '../lib/types';
import { MoneyList } from './Records';

const state = vi.hoisted(()=>({busy:false,uncertain:false,error:undefined,save:vi.fn(),retry:vi.fn()}));
vi.mock('../lib/api',()=>({ApiError:class extends Error {},useOnline:()=>true,useSave:()=>state,useData:()=>({loading:false,reload:()=>{},data:{data:[{id:'9',kind:'receipt',status:'posted',amount_paisa:'10025',business_date_bs:20830103}],last_page:1}})}));
vi.mock('@ionic/react',()=>({IonIcon:()=>null,IonSpinner:()=>null,IonButton:({children,type='button',...props}:React.ButtonHTMLAttributes<HTMLButtonElement>)=><button type={type} {...props}>{children}</button>}));
afterEach(()=>{state.busy=false;state.uncertain=false;state.save.mockClear();state.retry.mockClear();});
function page(role='owner') {return <MemoryRouter><Context.Provider value={{base:'/api/app/shop',path:'/app/shop',business:{role} as Business,today:20830103,revision:0,changed:()=>{},t:s=>s,locale:'en'}}><MoneyList/></Context.Provider></MemoryRouter>;}

it('keeps every money action reachable through small groups and history separate',()=>{
  const view=render(page());
  expect(screen.getByRole('link',{name:'Receive money'})).toHaveAttribute('href','/app/shop/money/receipt/new');
  expect(screen.getByRole('link',{name:'Pay party'})).toHaveAttribute('href','/app/shop/money/supplier_payment/new');
  expect(screen.queryByText('PAY-9')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button',{name:'Transfers / owner money'}));
  expect(screen.getByRole('link',{name:'Move money'})).toHaveAttribute('href','/app/shop/money/transfer/new');
  expect(screen.getByRole('link',{name:'Add my money'})).toBeInTheDocument();
  expect(screen.getByRole('link',{name:'Personal withdrawal'})).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button',{name:'Refunds'}));
  expect(screen.getByRole('link',{name:'Customer refund'})).toBeInTheDocument();
  expect(screen.getByRole('link',{name:'Payee refund'})).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button',{name:'History'}));
  expect(screen.getByText('PAY-9')).toBeInTheDocument();
  view.unmount();render(page('cashier'));
  expect(screen.queryByRole('button',{name:'Transfers / owner money'})).not.toBeInTheDocument();
});

it('retains cancellation date/reason payload and keeps context during correction',()=>{
  render(page());fireEvent.click(screen.getByRole('button',{name:'History'}));
  fireEvent.click(screen.getByRole('button',{name:'Cancel'}));
  expect(screen.getByRole('button',{name:'Receive / pay'})).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Reason'),{target:{value:'Wrong payment record'}});
  fireEvent.click(screen.getByRole('button',{name:'Confirm cancellation'}));
  expect(state.save).toHaveBeenCalledWith('/api/app/shop/payments/9/cancel',{business_date_bs:20830103,reason:'Wrong payment record'},expect.any(Function));
});

it('keeps uncertain correction locked and retries its original action',()=>{
  const view=render(page());fireEvent.click(screen.getByRole('button',{name:'History'}));fireEvent.click(screen.getByRole('button',{name:'Cancel'}));
  state.uncertain=true;view.rerender(page());
  expect(screen.getByLabelText('Reason')).toBeDisabled();
  expect(screen.getByRole('button',{name:'Close correction'})).toBeDisabled();
  fireEvent.click(screen.getByRole('button',{name:'Retry original action'}));
  expect(state.retry).toHaveBeenCalledOnce();expect(state.save).not.toHaveBeenCalled();
});
