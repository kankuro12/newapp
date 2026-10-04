// Visual QA fixture only. Never contacts authenticated API or posts financial data.
import React from 'react';
import { createRoot } from 'react-dom/client';
import { IonApp, IonContent, IonPage, setupIonicReact } from '@ionic/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { Context } from '../frontend/src/lib/context';
import { translator } from '../frontend/src/lib/i18n';
import { WorkflowDetail } from '../frontend/src/pages/Workflows';
import FulfilmentDetail from '../frontend/src/pages/FulfilmentDetail';
import '@ionic/react/css/core.css';
import '@ionic/react/css/normalize.css';
import '@ionic/react/css/structure.css';
import '@ionic/react/css/typography.css';
import '../frontend/src/theme/variables.css';
import '../frontend/src/theme/app.css';

setupIonicReact();
const query = new URLSearchParams(location.search);
const purchase = query.get('kind') === 'purchase';
const locale = query.get('locale') === 'ne' ? 'ne' : 'en';
const business = {name:'Kathmandu Milk Shop',role:'owner',opening_finalized_at:'2026-10-04',tax_recording_enabled:true};
const original = {description:'Fresh milk / ताजा दूध',item_kind:'stock',unit_snapshot:'L',qty_milli:'5000',unit_price_paisa:'10000',total_paisa:'50000'};
const stage = {id:'8',workflow_id:'9',number:purchase?'REC-000008':'DEL-000008',kind:purchase?'receipt':'delivery',status:'posted',version:1,business_date_bs:20830618,vat_recoverable:false,lines:[{id:'81',position:1,qty_milli:'2000',billable_qty_milli:'1500',returnable_qty_milli:'1500',item_snapshot:original}]};
const row = {id:'9',number:purchase?'PO-000009':'SO-000009',kind:purchase?'purchase_order':'sales_order',status:'in_progress',version:3,business_date_bs:20830618,fulfilment_vat_recoverable:false,party_snapshot:{name:purchase?'Lalitpur Dairy Supplier':'Bina Store',phone:'',address:'Kathmandu'},business_snapshot:business,lines:[original],subtotal_paisa:'50000',line_discount_paisa:'0',invoice_discount_paisa:'0',tax_paisa:'0',total_paisa:'50000',fulfilment:{active:true,lines:[{position:1,description:original.description,unit_snapshot:'L',ordered_qty_milli:'5000',completed_qty_milli:'2000',remaining_qty_milli:'3000',billed_qty_milli:'500',billable_qty_milli:'1500',billed_returned_qty_milli:'0'}],activity:[stage],bills:[{id:'20',number:'S-000020',status:'posted',business_date_bs:20830618,total_paisa:'5000'}]}};
window.fetch = async (input,options) => {
  const url=String(input);
  if(url.includes('csrf-cookie'))return new Response(null,{status:204});
  if(options?.method==='POST'){
    if(!url.endsWith('/preview'))return new Response(JSON.stringify({message:'Preview fixture. Posting disabled.'}),{status:422});
    const selection=JSON.parse(options.body as string).lines[0];
    const qty=String(BigInt(selection.qty.replace('.','').padEnd(selection.qty.includes('.')?selection.qty.split('.')[0].length+3:selection.qty.length+3,'0')));
    return new Response(JSON.stringify({data:{fingerprint:'a'.repeat(64),total_paisa:'12500',tax_paisa:'0',line_discount_paisa:'0',invoice_discount_paisa:'0',source_order_snapshot:{id:'9',number:row.number,allocation:'cumulative_source_order',lines:[]},lines:[{description:original.description,qty_milli:qty,unit_snapshot:'L',...(url.includes('/staged-bills/')?{total_paisa:'12500'}:{}),item_snapshot:original}]}}),{status:200});
  }
  return new Response(JSON.stringify({data:url.endsWith('/lookup')?{accounts:[{id:'1',name:'Cash'}]}:url.includes('/fulfilment/')?stage:row}),{status:200});
};
createRoot(document.getElementById('root')!).render(<IonApp><IonPage><IonContent><Context.Provider value={{business,base:'/api/app/visual-fixture',path:'',today:20830618,revision:0,changed:()=>{},t:translator(locale),locale}}><div className="main-wrap"><main className="page-wrap"><p className="notice">Preview fixture · posting disabled</p><MemoryRouter initialEntries={[query.get('slip')?'/fulfilment/8':'/workflow/9']}><Routes><Route path="workflow/:id" element={<WorkflowDetail/>}/><Route path="fulfilment/:id" element={<FulfilmentDetail/>}/></Routes></MemoryRouter></main></div></Context.Provider></IonContent></IonPage></IonApp>);
