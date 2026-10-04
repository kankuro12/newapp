import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { Context } from '../lib/context';
import type { Business, Dashboard as DashboardData } from '../lib/types';
import Dashboard from './Dashboard';

const home = vi.hoisted(() => ({sales_paisa:'120050',cash_paisa:'340099',receivables_paisa:'10025',payables_paisa:'24000',recent:Array.from({length:7},(_,i)=>({id:String(i+1),number:'SALE-'+(i+1),type:'sale',party_snapshot:{name:'Customer '+(i+1)},business_date_bs:20830103,total_paisa:'10000',status:'posted'})),low_stock:Array.from({length:7},(_,i)=>({id:String(i+1),name:'Item '+(i+1),qty_milli:'1250',unit_label:'kg'}))}));
vi.mock('../lib/api',()=>({ApiError:class extends Error {},useOnline:()=>true,useData:()=>({loading:false,data:{data:home as unknown as DashboardData},reload:()=>{}})}));
vi.mock('@ionic/react',()=>({IonIcon:()=>null,IonSpinner:()=>null,IonButton:({children,...props}:React.ButtonHTMLAttributes<HTMLButtonElement>)=><button {...props}>{children}</button>}));

function page(role='owner', initial='/app/shop') {
  return <MemoryRouter initialEntries={[initial]}><Context.Provider value={{business:{role,opening_finalized_at:'2026-10-04'} as Business,base:'/api/app/shop',path:'/app/shop',today:20830103,revision:0,changed:()=>{},t:s=>s,locale:'en'}}><Dashboard/></Context.Provider></MemoryRouter>;
}

it('opens daily actions first and shows exact balances only when selected',()=>{
  render(page());
  expect(screen.getByRole('link',{name:'Pay party'})).toHaveAttribute('href','/app/shop/money/supplier_payment/new');
  expect(screen.queryByText('Today’s sales')).not.toBeInTheDocument();
  expect(screen.queryByText('Customer 1')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button',{name:'Balances'}));
  expect(screen.getByText('Today’s sales')).toBeInTheDocument();
  expect(screen.getByText('रु 1,200.50')).toBeInTheDocument();
  expect(screen.queryByRole('link',{name:'Pay party'})).not.toBeInTheDocument();
});

it('pages every recent record and stock alert, preserving page when switching sections',()=>{
  render(page());
  fireEvent.click(screen.getByRole('button',{name:'Recent activity'}));
  expect(screen.getByRole('link',{name:/Customer 1/})).toHaveAttribute('href','/app/shop/document/1');
  expect(screen.queryByText('Customer 4')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button',{name:'Next'}));
  expect(screen.getByText('Customer 3')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button',{name:'Next'}));
  expect(screen.getByText('Customer 5')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button',{name:'Next'}));
  expect(screen.getByText('Customer 7')).toBeInTheDocument();
  expect(screen.getByRole('button',{name:'Next'})).toBeDisabled();
  fireEvent.click(screen.getByRole('button',{name:'Low stock'}));
  expect(screen.queryByText('Item 4')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button',{name:'Next'}));
  expect(screen.getByRole('link',{name:/Item 3/})).toHaveAttribute('href','/app/shop/items/3');
  fireEvent.click(screen.getByRole('button',{name:'Next'}));
  fireEvent.click(screen.getByRole('button',{name:'Next'}));
  expect(screen.getByText('Item 7')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button',{name:'Recent activity'}));
  expect(screen.getByText('Customer 7')).toBeInTheDocument();
});

it('rejects cashier balance section while retaining sales and stock views',()=>{
  render(page('cashier','/app/shop?view=balances'));
  expect(screen.queryByRole('button',{name:'Balances'})).not.toBeInTheDocument();
  expect(screen.getByRole('button',{name:'Daily actions'})).toHaveAttribute('aria-pressed','true');
  expect(screen.queryByRole('link',{name:'Pay party'})).not.toBeInTheDocument();
  expect(screen.queryByText('Cash & bank')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button',{name:'Recent activity'}));
  expect(screen.getByText('Customer 1')).toBeInTheDocument();
});
