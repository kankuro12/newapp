import { IonButton } from '@ionic/react';
import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useWorkspace } from '../lib/context';
import { request, useOnline } from '../lib/api';
import { currency } from '../lib/money';
import { DEFAULT_LABEL_FORMAT, labelPages } from '../lib/barcode';
import type { LabelFormat, LabelRow, PrintedLabel } from '../lib/barcode';
import type { Item, Party } from '../lib/types';
import { Check, Errors, Field, Heading, OptionalDetails, Select, Submit } from '../components/ui';
import Picker from '../components/Picker';
import EntrySteps from '../components/EntrySteps';
import { CategoryFilter } from './CatalogTools';

type LabelItem=Pick<Item,'id'|'name'|'sku'|'aliases'|'sale_price_paisa'|'unit_label'>;
interface Choice {item:LabelItem;code:string|null;copies:number}
interface Proof {signature:string;format:LabelFormat;layout:ReturnType<typeof labelPages>}

export default function LabelTools(){
  const {base,path,business,t}=useWorkspace();const online=useOnline();
  const key='business-book-label-format:'+business.id;
  const [format,setFormat]=useState<LabelFormat>(()=>{try{return {...DEFAULT_LABEL_FORMAT,...JSON.parse(localStorage.getItem(key)||'{}')};}catch{return DEFAULT_LABEL_FORMAT;}});
  const [rows,setRows]=useState<Choice[]>([]);const [category,setCategory]=useState<{id:string;name:string}>();const [supplier,setSupplier]=useState<Party>();
  const [busy,setBusy]=useState(false);const [error,setError]=useState<Error>();const [notice,setNotice]=useState('');const [proof,setProof]=useState<Proof>();
  const controller=useRef<AbortController|undefined>(undefined);
  const root=useRef<HTMLDivElement>(null);
  useEffect(()=>()=>controller.current?.abort(),[base]);
  const input={rows:rows.map(row=>({item_id:row.item.id,code:row.code,copies:row.copies})),include_tax:format.includeTax};
  const signature=JSON.stringify({input,format});const ready=proof?.signature===signature;
  function add(items:LabelItem[]){setRows(previous=>{const next=[...previous];for(const item of items)if(!next.some(row=>row.item.id===item.id))next.push({item,code:item.sku||item.aliases?.[0]||null,copies:1});return next;});}
  function update(index:number,patch:Partial<Choice>){setRows(previous=>previous.map((row,i)=>i===index?{...row,...patch}:row));}
  function physical(patch:Partial<LabelFormat>){setFormat(previous=>({...previous,...patch}));}
  async function bulk(){if(busy||controller.current||!online)return;const abort=new AbortController();controller.current=abort;setBusy(true);setError(undefined);try{const query=new URLSearchParams();if(category)query.set('category_id',category.id);if(supplier)query.set('supplier_id',supplier.id);const result=await request<{data:LabelItem[]}>(base+'/barcodes/items?'+query,{signal:abort.signal});if(!abort.signal.aborted){add(result.data);setNotice(t('Matching items added')+': '+result.data.length);}}catch(cause){if(!abort.signal.aborted)setError(cause as Error);}finally{if(!abort.signal.aborted){setBusy(false);controller.current=undefined;}}}
  async function preview(print=false){
    if(busy||controller.current||!online)return;setError(undefined);setNotice('');
    try{labelPages(rows.map(row=>({item_id:row.item.id,name:row.item.name,code:row.code,copies:row.copies,unit_label:row.item.unit_label,price_paisa:row.item.sale_price_paisa,tax_paisa:'0'})),format);}catch(cause){setProof(undefined);setError(cause as Error);return;}
    const abort=new AbortController();controller.current=abort;setBusy(true);
    try{const result=await request<{data:{rows:LabelRow[]}}>(base+'/barcodes/labels',{method:'POST',body:JSON.stringify(input),signal:abort.signal});if(!abort.signal.aborted){const layout=labelPages(result.data.rows,format);setProof({signature,format:{...format},layout});await new Promise<void>(resolve=>requestAnimationFrame(()=>{if(!abort.signal.aborted){const clipped=Array.from(root.current?.querySelectorAll<HTMLElement>('.product-label')||[]).some(label=>label.scrollWidth>label.clientWidth+1||label.scrollHeight>label.clientHeight+1);if(clipped){setProof(undefined);setError(new Error(t('Printed contents do not fit. Use a larger label or hide optional fields.')));}else if(print)window.print();}resolve();}));}}
    catch(cause){if(!abort.signal.aborted){setProof(undefined);setError(cause as Error);}}
    finally{if(!abort.signal.aborted){setBusy(false);controller.current=undefined;}}
  }
  return <div ref={root} className="labels-workspace"><div className="no-print"><Heading title={t('Product labels')} description={t('Choose items and copies. Review fresh prices, then print. No stock or money changes.')}><Link to={path+'/barcodes'}>{t('Product codes / scale setup')}</Link><Link to={path+'/items'}>{t('Items')}</Link></Heading><p role="status">{notice}</p></div>
    <EntrySteps labels={[t('Choose items'),t('Paper & layout'),t('Review & print')]} t={t} dirty={rows.length>0} busy={busy} error={error} canContinue={[rows.length>0,true]} onSubmit={event=>{event.preventDefault();void preview();}}>
      <section className="panel no-print" data-entry-step="0"><h2>{t('Choose items')}</h2><fieldset disabled={busy} className="import-fields"><Picker kind="items" onPick={row=>{if(!busy)add([row as Item]);}}/><OptionalDetails label={t('Add by category / supplier')}><CategoryFilter value={category} onChange={setCategory}/>{business.role!=='cashier'&&<><Picker kind="contacts" customer={false} onPick={row=>{if(!busy)setSupplier(row as Party);}}/>{supplier&&<p>{supplier.name} <button type="button" onClick={()=>setSupplier(undefined)}>{t('Remove')}</button></p>}</>}<IonButton fill="outline" disabled={busy||!online||(!category&&!supplier)} onClick={()=>void bulk()}>{t('Add matching items')}</IonButton></OptionalDetails>
        {rows.map((row,index)=><div className="label-choice" key={row.item.id}><strong>{row.item.name}</strong><div className="form-grid"><Select label={`${t('Barcode')}: ${row.item.name}`} value={row.code??''} onChange={value=>update(index,{code:value||null})}><option value="">{t('No barcode')}</option>{[...new Set([row.item.sku,...row.item.aliases||[]].filter((code):code is string=>code!==null&&code!==undefined&&code!==''))].map(code=><option key={code} value={code}>{code}</option>)}</Select><Field label={`${t('Copies')}: ${row.item.name}`} type="number" required min={1} max={1000} step={1} value={row.copies} onChange={event=>update(index,{copies:Number(event.target.value)})}/></div><IonButton fill="clear" disabled={busy} onClick={()=>setRows(previous=>previous.filter((_,i)=>i!==index))}>{t('Remove')}</IonButton></div>)}
      </fieldset><small>{t('Up to 100 items / 1000 labels. ASCII SKU or alternate code; no barcode allows text-only labels.')}</small></section>
      <section className="panel no-print" data-entry-step="1"><h2>{t('Paper & layout')}</h2><fieldset disabled={busy} className="import-fields"><Select label={t('Quick paper setup')} value="" onChange={value=>{if(value==='sheet')setFormat({...DEFAULT_LABEL_FORMAT});if(value==='roll')setFormat({...DEFAULT_LABEL_FORMAT,paperWidth:50,paperHeight:30,width:48,height:28,columns:1,margin:1,gapX:0,gapY:0,barHeight:8});}}><option value="">{t('Custom / current settings')}</option><option value="sheet">A4 · 60 × 35 mm</option><option value="roll">{t('Roll')} · 50 × 30 mm</option></Select>
        <div className="form-grid">{(['paperWidth','paperHeight','width','height','columns','margin'] as const).map((name,index)=><Field key={name} label={t(['Paper width (mm)','Paper height (mm)','Label width (mm)','Label height (mm)','Columns','Page margin (mm)'][index])} type="number" step={name==='columns'?1:0.1} min={name==='margin'?0:1} required value={format[name]} onChange={event=>physical({[name]:Number(event.target.value)})}/>)}</div>
        <OptionalDetails label={t('Gaps / barcode calibration')}><div className="form-grid">{(['gapX','gapY','moduleWidth','barHeight'] as const).map((name,index)=><Field key={name} label={t(['Horizontal gap (mm)','Vertical gap (mm)','Narrow bar width (mm)','Barcode height (mm)'][index])} type="number" required min={name==='moduleWidth'?0.2:0} step={name==='moduleWidth'?0.01:0.1} value={format[name]} onChange={event=>physical({[name]:Number(event.target.value)})}/>)}</div></OptionalDetails>
        <div className="label-options">{(['showName','showPrice','showCode','includeTax'] as const).map((name,index)=><Check key={name} checked={format[name]} onChange={value=>physical({[name]:value})}>{t(['Print item name','Print standard price','Print readable code','Include configured bill tax'][index])}</Check>)}</div>
        <IonButton fill="outline" disabled={busy} onClick={()=>{try{localStorage.setItem(key,JSON.stringify(format));setNotice(t('Paper settings remembered on this browser'));}catch{setError(new Error(t('Could not remember paper settings.')));}}}>{t('Remember paper settings')}</IonButton></fieldset><p>{t('Print at 100% / actual size, no browser headers. Match printer paper and calibrate one label first.')}</p><small>{t('Prices use standard unit price and optional configured tax. Party discounts are separate.')}</small></section>
      <section className="panel label-review" data-entry-step="2"><div className="no-print"><h2>{t('Review & print')}</h2><Errors error={error}/><p>{rows.length} {t('Items')} · {rows.reduce((sum,row)=>sum+row.copies,0)} {t('Labels')}</p><Submit busy={busy} disabled={busy||!online||!rows.length}>{t('Preview labels')}</Submit><IonButton disabled={busy||!online||!ready} onClick={()=>void preview(true)}>{t('Print labels')}</IonButton><p>{t('Printing refreshes item codes and prices again. Changes require a new preview.')}</p>{ready&&<p>{proof!.layout.pages.length} {t('Pages')} · {format.width} × {format.height} mm</p>}</div>
        {ready&&<LabelSheets proof={proof!} t={t}/>}</section>
    </EntrySteps></div>;
}

function LabelSheets({proof,t}:{proof:Proof;t:(value:string)=>string}){
  const f=proof.format;
  return <div className="label-preview"><style>{`@media print { @page { size:${f.paperWidth}mm ${f.paperHeight}mm; margin:0; } }`}</style>{proof.layout.pages.map((page,index)=><div className="label-page" key={index} style={{width:`${f.paperWidth}mm`,height:`${f.paperHeight}mm`,padding:`${f.margin}mm`,gridTemplateColumns:`repeat(${f.columns},${f.width}mm)`,gridAutoRows:`${f.height}mm`,columnGap:`${f.gapX}mm`,rowGap:`${f.gapY}mm`}}>{page.map((row,i)=><Label key={i} row={row} format={f} t={t}/>)}</div>)}</div>;
}
function Label({row,format:f,t}:{row:PrintedLabel;format:LabelFormat;t:(value:string)=>string}){
  return <div className="product-label" style={{width:`${f.width}mm`,height:`${f.height}mm`}}>{f.showName&&<strong className="label-name" title={row.name}>{row.name}</strong>}{f.showPrice&&<span className="label-price">{currency(row.price_paisa)} / {row.unit_label}{BigInt(row.tax_paisa)>0n&&<small> {t(f.includeTax?'incl. tax':'+ tax')}</small>}</span>}{row.barcode&&<svg role="img" aria-label={`${t('Barcode')} ${row.code}`} width={`${row.barcode.width*f.moduleWidth}mm`} height={`${f.barHeight}mm`} viewBox={`0 0 ${row.barcode.width} 1`} preserveAspectRatio="none" shapeRendering="crispEdges"><rect width={row.barcode.width} height={1} fill="white"/>{row.barcode.bars.map((bar,index)=><rect key={index} x={bar.x} width={bar.width} height={1} fill="black"/>)}</svg>}{f.showCode&&row.barcode&&<span className="label-readable">{row.code}</span>}</div>;
}
