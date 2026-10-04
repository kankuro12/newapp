import { amount, format, quantity, round } from './money';

export interface PriceSegment {qty_milli:string;price_paisa:string;gross_paisa?:string;from_qty_milli?:string;to_qty_milli?:string}
export interface QuantityPrice {item_id:string;price_paisa:string;pricing_scheme?:string;qty_milli?:string;gross_paisa?:string;segments?:PriceSegment[]}
interface PriceLine {item_id?:string;qty:string;unit_price:string;discount?:string;discount_mode?:string;tax_bps?:string;tax_category?:string;tax_rate?:string}

export function applyQuantityPrices<T extends PriceLine>(rows:T[],quotes:QuantityPrice[]):T[]{
  const output:T[]=[];const completed=new Set<string>();
  for(const row of rows){
    const quote=quotes.find(price=>price.item_id===row.item_id);
    if(!quote){output.push({...row});continue;}
    if(quote.pricing_scheme!=='slab'){output.push({...row,unit_price:format(quote.price_paisa)});continue;}
    if(completed.has(quote.item_id))continue;completed.add(quote.item_id);
    const same=rows.filter(line=>line.item_id===quote.item_id);
    const signature=(line:T)=>JSON.stringify([line.tax_category||'outside_scope',(line.tax_rate===undefined?BigInt(line.tax_bps||'0'):amount(line.tax_rate)).toString(),line.discount_mode==='percent'?'percent':'fixed',line.discount_mode==='percent'?amount(line.discount||'0').toString():'']);
    if(same.some(line=>signature(line)!==signature(row)))throw new Error('Same item has different tax or percentage discounts. Review rows before applying slab prices.');
    const qty=same.reduce((sum,line)=>sum+quantity(line.qty),0n);const segments=quote.segments||[];
    if(!segments.length||qty!==BigInt(quote.qty_milli||'0')||segments.reduce((sum,segment)=>sum+BigInt(segment.qty_milli),0n)!==qty)throw new Error('Quantity changed. Review prices again.');
    const gross=segments.map(segment=>round(BigInt(segment.qty_milli),BigInt(segment.price_paisa),1000n));const total=gross.reduce((sum,value)=>sum+value,0n);
    const discount=row.discount_mode==='percent'?0n:same.reduce((sum,line)=>sum+amount(line.discount||'0'),0n);
    if(discount>total)throw new Error('Discount exceeds slab price. Review discount before applying.');
    const shares=gross.map(value=>total?discount*value/total:0n);
    const remainders=gross.map((value,index)=>({index,remainder:total?discount*value%total:0n})).sort((a,b)=>a.remainder===b.remainder?a.index-b.index:a.remainder>b.remainder?-1:1);
    const left=discount-shares.reduce((sum,value)=>sum+value,0n);for(let i=0;BigInt(i)<left;i++)shares[remainders[i].index]++;
    segments.forEach((segment,index)=>output.push({...row,qty:format(segment.qty_milli,3),unit_price:format(segment.price_paisa),...(row.discount_mode==='percent'?{}:{discount:format(shares[index])})}));
  }
  if(output.length>100)throw new Error('Quantity ranges exceed 100 bill rows. Split this bill.');
  return output;
}
