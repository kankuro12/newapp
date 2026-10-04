import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { Context } from '../lib/context';
import type { Business } from '../lib/types';
import { MasterForm } from './Masters';

const state=vi.hoisted(()=>({save:vi.fn()}));
vi.mock('../lib/api',()=>({ApiError:class extends Error{},useOnline:()=>true,send:vi.fn(),useData:()=>({loading:false,data:undefined,reload:vi.fn()}),useSave:()=>({busy:false,uncertain:false,save:state.save})}));
vi.mock('./Records',()=>({Pagination:()=>null}));
vi.mock('./CatalogTools',()=>({CategoryFilter:()=>null}));
vi.mock('../components/Picker',()=>({default:()=>null}));
vi.mock('@ionic/react',()=>({IonButton:({children,type='button',...props}:React.ButtonHTMLAttributes<HTMLButtonElement>)=><button type={type} {...props}>{children}</button>,IonIcon:()=>null,IonSpinner:()=>null}));

it('configures a readable square-inch base and custom sheet without losing exact conversion',()=>{
  state.save.mockClear();
  render(<MemoryRouter><Context.Provider value={{base:'/api/app/glass',path:'/app/glass',business:{id:'1',name:'Glass',role:'owner',pos_profile:'glass'} as Business,today:20830103,revision:0,changed:()=>{},t:s=>s,locale:'en'}}><MasterForm resource="items"/></Context.Provider></MemoryRouter>);
  expect(screen.getByLabelText('Billed base unit')).toHaveValue('sq_ft');
  fireEvent.click(screen.getByText('POS measurements / scheduling'));
  expect(screen.getByRole('option',{name:'Square inch (in²)'})).toHaveValue('sq_in');
  expect(screen.getByRole('option',{name:'US gallon (gal US)'})).toHaveValue('us_gal');
  expect(screen.getByRole('option',{name:'Imperial gallon (gal UK)'})).toHaveValue('imp_gal');
  fireEvent.change(screen.getByLabelText('Name'),{target:{value:'Glass sheet'}});
  fireEvent.change(screen.getByLabelText('Billed base unit'),{target:{value:'sq_in'}});
  fireEvent.click(screen.getByRole('button',{name:'Add custom unit'}));
  fireEvent.change(screen.getByLabelText('Custom unit name'),{target:{value:'Small sheet'}});
  fireEvent.change(screen.getByLabelText('Quantity in base units'),{target:{value:'144.125'}});
  fireEvent.submit(screen.getByRole('button',{name:'Save'}).closest('form')!);
  expect(state.save.mock.calls[0][1]).toMatchObject({pos_unit:'sq_in',unit_label:'sq_in',pos_custom_units:[{label:'Small sheet',qty:'144.125'}],pos_methods:['quantity','amount','pack','length','area','volume']});
});
