import { describe, expect, it } from 'vitest';
import { amount, calculate, currency, format, quantity } from './money';

describe('exact previews', () => {
  it('normalizes Nepali digits and retains paisa', () => {
    expect(amount('१२३.४५')).toBe(12345n);
    expect(quantity('0.125')).toBe(125n);
    expect(format('12345')).toBe('123.45');
    expect(() => amount('1e3')).toThrow();
    expect(currency(-50n)).toBe('रु -0.50');
  });
  it('allocates paisa and rounds tax without floats', () => {
    expect(calculate([{ qty: '1', unit_price: '1' }, { qty: '1', unit_price: '1' }, { qty: '1', unit_price: '1' }], '0.01').lines.map(x => x.invoiceDiscount)).toEqual([1n, 0n, 0n]);
    expect(calculate([{ qty: '0.125', unit_price: '10', discount_bps: '1000', tax_bps: '1300', tax_category: 'standard' }], '0.01').total).toBe(125n);
    expect(calculate([{ qty: '1', unit_price: '100' }], '0', '1000').total).toBe(9000n);
  });
});
