import { expect, it } from 'vitest';
import { applyQuantityPrices } from './pricing';
import { calculate } from './money';

const quote={item_id:'4',price_paisa:'10025',pricing_scheme:'slab',qty_milli:'2501',gross_paisa:'25072',segments:[{qty_milli:'2500',price_paisa:'10025'},{qty_milli:'1',price_paisa:'9035'}]};
it('consolidates duplicate items and splits exact slabs while preserving fixed discounts and tax',()=>{
  const rows=[{item_id:'4',name:'Milk',qty:'1.25',unit_price:'120',discount:'25',discount_mode:'fixed',tax_bps:'1300',tax_category:'standard'},{item_id:'4',name:'Milk',qty:'1.251',unit_price:'120',discount:'5',discount_mode:'fixed',tax_bps:'1300',tax_category:'standard'}];
  const result=applyQuantityPrices(rows,[quote]);
  expect(result.map(row=>[row.qty,row.unit_price])).toEqual([['2.500','100.25'],['0.001','90.35']]);
  expect(result.map(row=>row.discount)).toEqual(['29.99','0.01']);
  expect(calculate(result).subtotal).toBe(25072n);
  expect(calculate(result).total).toBe(24941n);
  expect(rows[0].unit_price).toBe('120');
});
it('leaves ordinary/volume rows editable and rejects stale quantities, conflicting tax and too-large discounts',()=>{
  const row={item_id:'4',qty:'2.501',unit_price:'120',discount:'300',tax_bps:'0',tax_category:'outside_scope'};
  expect(()=>applyQuantityPrices([row],[quote])).toThrow('Discount exceeds');
  expect(()=>applyQuantityPrices([{...row,qty:'2',discount:'0'}],[quote])).toThrow('Quantity changed');
  expect(()=>applyQuantityPrices([{...row,qty:'1',discount:'0'},{...row,qty:'1.501',discount:'0',tax_bps:'1300'}],[quote])).toThrow('Same item');
  expect(applyQuantityPrices([{...row,discount:'0'}],[{item_id:'4',price_paisa:'9035',pricing_scheme:'volume'}])[0].unit_price).toBe('90.35');
});

it('recognizes equivalent tax entry formats and retains percentage discounts on every range',()=>{
  const rows=[{item_id:'4',qty:'1',unit_price:'120',discount:'10',discount_mode:'percent',tax_bps:'0',tax_rate:'13',tax_category:'standard'},{item_id:'4',qty:'1.501',unit_price:'120',discount:'10.00',discount_mode:'percent',tax_bps:'1300',tax_category:'standard'}];
  const result=applyQuantityPrices(rows,[quote]);
  expect(result).toHaveLength(2);
  expect(result.every(row=>row.discount_mode==='percent'&&row.discount==='10')).toBe(true);
  expect(result[0].tax_rate).toBe('13');
});
