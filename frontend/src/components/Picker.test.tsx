import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import Picker from './Picker';
import { Context } from '../lib/context';
import type { WorkspaceContext } from '../lib/context';
afterEach(()=>vi.unstubAllGlobals());
it('selects a searched item category from category lookup',async()=>{
 const fetcher=vi.fn<typeof fetch>(async()=>new Response(JSON.stringify({data:{item_categories:[{id:'9',name:'Drinks',version:1}],items:[],contacts:[],accounts:[],categories:[]}})));
 vi.stubGlobal('fetch',fetcher);const pick=vi.fn();const context={base:'/api/app/shop',path:'/app/shop',revision:0,t:(s:string)=>s} as WorkspaceContext;
 render(<Context.Provider value={context}><Picker kind="item_categories" onPick={pick}/></Context.Provider>);
 const input=screen.getByRole('textbox',{name:'Item category'});fireEvent.focus(input);fireEvent.change(input,{target:{value:'Drinks'}});
 await waitFor(()=>expect(screen.getByRole('button',{name:/Drinks/})).toBeInTheDocument());
 expect(fetcher.mock.calls.some(call=>String(call[0]).includes('item_categories=1'))).toBe(true);
 fireEvent.keyDown(input,{key:'Enter'});expect(pick).toHaveBeenCalledWith(expect.objectContaining({id:'9',name:'Drinks'}));
});
it('Enter selects matching party without advancing outer billing step',async()=>{
 vi.stubGlobal('fetch',vi.fn(async()=>new Response(JSON.stringify({data:{contacts:[{id:'1',name:'Ram',is_customer:true,is_system:false}],items:[],accounts:[],categories:[]}}))));
 const pick=vi.fn();const advance=vi.fn();const context={base:'/api/app/shop',path:'/app/shop',revision:0,t:(s:string)=>s} as WorkspaceContext;
 render(<Context.Provider value={context}><div onKeyDown={advance}><Picker kind="contacts" customer onPick={pick}/></div></Context.Provider>);
 const input=screen.getByRole('textbox',{name:'Customer'});fireEvent.focus(input);fireEvent.change(input,{target:{value:'Ram'}});await waitFor(()=>expect(screen.getByRole('button',{name:/Ram/})).toBeInTheDocument());
 fireEvent.keyDown(input,{key:'Enter'});expect(pick).toHaveBeenCalledWith(expect.objectContaining({id:'1'}));expect(advance).not.toHaveBeenCalled();
});
it('does not select one of several parties sharing same name',async()=>{
 vi.stubGlobal('fetch',vi.fn(async()=>new Response(JSON.stringify({data:{contacts:[{id:'1',name:'Ram',is_system:false},{id:'2',name:'Ram',is_system:false}],items:[],accounts:[],categories:[]}}))));
 const pick=vi.fn(); const context={base:'/api/app/shop',path:'/app/shop',revision:0,t:(s:string)=>s} as WorkspaceContext;
 render(<Context.Provider value={context}><Picker kind="contacts" customer onPick={pick}/></Context.Provider>);
 const input=screen.getByRole('textbox',{name:'Customer'});fireEvent.focus(input);fireEvent.change(input,{target:{value:'Ram'}});await waitFor(()=>expect(screen.getAllByRole('button',{name:/Ram/})).toHaveLength(2));
 fireEvent.keyDown(input,{key:'Enter'});expect(pick).not.toHaveBeenCalled();expect(screen.getAllByRole('button',{name:/Ram/})[0]).toHaveFocus();
});
