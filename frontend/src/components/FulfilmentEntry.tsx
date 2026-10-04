import { IonButton } from '@ionic/react';
import { useState } from 'react';
import { send, useSave } from '../lib/api';
import { useWorkspace } from '../lib/context';
import { bsDisplay, currency, format, quantity } from '../lib/money';
import type { Fulfilment, Lookup, SourceOrderSnapshot, Workflow } from '../lib/types';
import { Check, Errors, Field, OptionalDetails, Select, Submit } from './ui';
import { SourceOrderAllocation } from './SourceOrderAllocation';

type Mode = 'record'|'return'|'bill'|'cancel';
interface Review { fingerprint:string; total_paisa?:string; tax_paisa?:string; line_discount_paisa?:string; invoice_discount_paisa?:string; source_order_snapshot?:SourceOrderSnapshot; lines:{description?:string;qty_milli:string;unit_snapshot?:string;total_paisa?:string;item_snapshot?:{description:string;unit_snapshot:string}}[] }

export function FulfilmentEntry({order, mode, source, accounts, done}: {order:Workflow; mode:Mode; source?:Fulfilment; accounts:Lookup['accounts']; done:(id?:string)=>void}) {
  const {base,path,t,today,business}=useWorkspace(); const form=useSave();
  const [quantities,setQuantities]=useState<Record<string,string>>({}); const [date,setDate]=useState(bsDisplay(today));
  const [paid,setPaid]=useState('0'); const [account,setAccount]=useState(''); const [due,setDue]=useState('');
  const [reference,setReference]=useState(''); const [notes,setNotes]=useState(''); const [reason,setReason]=useState('');
  const [recoverable,setRecoverable]=useState(false); const [overdraft,setOverdraft]=useState(false);
  const [review,setReview]=useState<{signature:string;data:Review}>(); const [reviewing,setReviewing]=useState(false); const [error,setError]=useState<Error>();
  const purchase=order.kind==='purchase_order'; const locked=form.busy||form.uncertain||reviewing;
  const vat=!!(source?.vat_recoverable??order.fulfilment_vat_recoverable??recoverable);
  const endpoint=mode==='cancel'?`${base}/fulfilment/${source!.id}/cancel`:`${base}/workflow/${order.id}/${mode==='bill'?'staged-bills':'fulfilments'}`;
  const choices=mode==='bill'?(order.fulfilment?.activity||[]).filter(stage=>stage.status==='posted'&&!stage.source_id).flatMap(stage=>stage.lines.filter(line=>BigInt(line.billable_qty_milli)>0n).map(line=>({key:line.id,name:line.item_snapshot.description+' · '+stage.number,unit:line.item_snapshot.unit_snapshot,limit:line.billable_qty_milli})))
    :source?source.lines.filter(line=>BigInt(line.returnable_qty_milli)>0n).map(line=>({key:String(line.position),name:line.item_snapshot.description,unit:line.item_snapshot.unit_snapshot,limit:line.returnable_qty_milli}))
    :(order.fulfilment?.lines||[]).filter(line=>BigInt(line.remaining_qty_milli)>0n).map(line=>({key:String(line.position),name:line.description,unit:line.unit_snapshot,limit:line.remaining_qty_milli}));
  const selected=choices.filter(line=>(quantities[line.key]||'').trim()!=='').map(line=>({[mode==='bill'?'fulfilment_line_id':'position']:mode==='bill'?line.key:Number(line.key),qty:quantities[line.key]}));
  const input:Record<string,unknown>=mode==='cancel'?{version:source!.version,workflow_version:order.version,business_date_bs:date,reason}
    :mode==='bill'?{version:order.version,business_date_bs:date,due_date_bs:due||null,paid_now:paid,money_account_id:account||null,supplier_bill_number:reference||null,overdraft_confirmed:overdraft,vat_recoverable:vat,notes:notes||null,lines:selected}
    :{version:order.version,business_date_bs:date,source_id:source?.id||null,vat_recoverable:vat,reference:reference||null,notes:notes||null,lines:selected};
  const signature=JSON.stringify(input); const current=form.busy||form.uncertain?review?.data:review?.signature===signature?review.data:undefined;
  const title=mode==='cancel'?'Cancel recorded action':mode==='bill'?'Bill completed quantities':mode==='return'?'Return unbilled quantities':purchase?'Receive goods / work':'Deliver goods / complete work';
  const confirmation=mode==='cancel'?'Confirm cancellation':mode==='bill'?'Confirm bill + payment':mode==='return'?'Confirm unbilled return':purchase?'Confirm receipt / work':'Confirm delivery / work';
  async function reviewEntry() {
    if(locked)return; setError(undefined); form.clear();
    try {
      if(!selected.length)throw new Error(t('Enter at least one quantity.'));
      for(const choice of choices){const text=quantities[choice.key];if(text?.trim()){const value=quantity(text);if(value<=0n||value>BigInt(choice.limit))throw new Error(t('Quantity must be positive and within available quantity.'));}}
      setReviewing(true); const result=await send<{data:Review}>(endpoint+'/preview',input); setReview({signature,data:result.data});
    } catch(cause){setReview(undefined);setError(cause instanceof Error?cause:new Error(t('Could not review entry.')));} finally {setReviewing(false);}
  }
  function confirm() {
    if(locked||mode!=='cancel'&&!current)return;
    const payload=mode==='cancel'?input:{...input,expected_fingerprint:current!.fingerprint,...(mode==='bill'?{expected_total_paisa:current!.total_paisa}:{})};
    void form.save<{id:string}>(endpoint,payload,data=>done(mode==='bill'?data.id:undefined));
  }
  return <form className="panel fulfilment-entry" data-dirty={Object.values(quantities).some(Boolean)||!!(reason||reference||notes||due||account)||paid!=='0'||date!==bsDisplay(today)||form.uncertain} onSubmit={event=>{event.preventDefault();if(mode==='cancel'||current)confirm();else void reviewEntry();}}>
    <h2>{t(title)}</h2>{source&&<p>{source.number} · {bsDisplay(source.business_date_bs)} BS</p>}
    <p>{t(mode==='bill'?'Use completed quantities only. Posting this bill does not move stock again.':mode==='return'?'Return quantities from this action only. For billed goods, open the original bill.':mode==='cancel'?'Reverse dependent bills and returns first. Later stock activity may block cancellation.':'Stock moves now; customer/supplier dues wait until a bill is posted.')}</p>
    <fieldset disabled={locked}>
      <Field label={t('Business date (BS)')} name="business_date_bs" value={date} onChange={event=>setDate(event.target.value)} required/>
      {mode==='cancel'?<Field label={t('Reason')} value={reason} minLength={3} maxLength={500} onChange={event=>setReason(event.target.value)} required/>:<>
        {choices.map(choice=><div className="fulfilment-quantity" key={choice.key}><p>{t('Available')}: {format(choice.limit,3)} {choice.unit}</p><Field label={choice.name+' · '+t('Quantity')} inputMode="decimal" value={quantities[choice.key]||''} onChange={event=>setQuantities({...quantities,[choice.key]:event.target.value})} placeholder="0.000"/><IonButton fill="clear" disabled={locked} onClick={()=>setQuantities({...quantities,[choice.key]:format(choice.limit,3)})}>{t('Use available quantity')}</IonButton></div>)}
        {!choices.length&&<p>{t('No available quantities. Refresh order or review existing records.')}</p>}
        {mode==='bill'?<><Field label={t('Paid now')} inputMode="decimal" value={paid} onChange={event=>setPaid(event.target.value)} required/><Select label={t('Payment account')} value={account} onChange={setAccount}><option value="">{t('Choose account when paying')}</option>{accounts.map(row=><option key={row.id} value={row.id}>{row.name}</option>)}</Select><OptionalDetails label={t('Additional bill details')}><Field label={t('Bill payment due date (BS, optional)')} value={due} onChange={event=>setDue(event.target.value)}/>{purchase&&<Field label={t('Supplier bill reference')} maxLength={100} value={reference} onChange={event=>setReference(event.target.value)}/>}<Field label={t('Notes')} maxLength={1000} value={notes} onChange={event=>setNotes(event.target.value)}/>{purchase&&['owner','accountant'].includes(business.role)&&<Check checked={overdraft} onChange={setOverdraft}>{t('Confirm bank overdraft')}</Check>}</OptionalDetails></>
          :<OptionalDetails label={t('Additional details')}><Field label={t('Reference')} value={reference} maxLength={150} onChange={event=>setReference(event.target.value)}/><Field label={t('Notes')} value={notes} maxLength={1000} onChange={event=>setNotes(event.target.value)}/>{purchase&&business.tax_recording_enabled&&!source&&order.fulfilment_vat_recoverable==null&&<Check checked={recoverable} onChange={setRecoverable}>{t('Tax is recoverable')}</Check>}</OptionalDetails>}
        {purchase&&(source||order.fulfilment_vat_recoverable!=null)&&<p>{t(vat?'Receipt tax treatment: recoverable':'Receipt tax treatment: included in cost')}</p>}
      </>}
    </fieldset>
    <Errors error={error||form.error}/>
    {current&&<section className="fulfilment-review" aria-label={t('Reviewed quantities')}><h3>{t('Reviewed quantities')}</h3>{current.lines.map((line,index)=><p key={index}><strong>{line.description||line.item_snapshot?.description}</strong><span>{format(line.qty_milli,3)} {line.unit_snapshot||line.item_snapshot?.unit_snapshot}{line.total_paisa!==undefined&&' · '+currency(line.total_paisa)}</span></p>)}{mode==='bill'&&<><strong>{t('Total')}: {currency(current.total_paisa)}</strong><p>{t('Tax')}: {currency(current.tax_paisa)}</p><p>{t('Paid now')}: {paid} · {t('Payment account')}: {accounts.find(row=>row.id===account)?.name||t('None')}</p><SourceOrderAllocation path={path} t={t} source={current.source_order_snapshot} discountPaisa={(BigInt(current.line_discount_paisa||'0')+BigInt(current.invoice_discount_paisa||'0')).toString()}/></>}</section>}
    <div className="detail-actions">{mode!=='cancel'&&!form.uncertain&&<IonButton fill={current?'outline':'solid'} disabled={locked||!choices.length||!business.opening_finalized_at} onClick={()=>void reviewEntry()}>{t(reviewing?'Reviewing…':'Review quantities')}</IonButton>}{(current||mode==='cancel')&&!form.uncertain&&<Submit busy={form.busy} disabled={reviewing||!business.opening_finalized_at}>{t(confirmation)}</Submit>}{form.uncertain&&<IonButton disabled={form.busy} onClick={()=>void form.retry()}>{t('Retry original action')}</IonButton>}<IonButton fill="clear" disabled={locked} onClick={()=>done()}>{t('Close entry')}</IonButton></div>
  </form>;
}
