import { expect, it } from 'vitest';
import { code128, DEFAULT_LABEL_FORMAT, labelPages } from './barcode';

it('encodes known B/C vectors with checksum, full stop and quiet zones', () => {
  const letters = code128('AB');
  expect(letters.values).toEqual([104, 33, 34, 102, 106]);
  expect(letters.width).toBe(77);
  expect(letters.bars[0]).toEqual({ x: 10, width: 2 });
  expect(letters.bars.at(-1)).toEqual({ x: 65, width: 2 });
  expect(code128('00000123').values).toEqual([105, 0, 0, 1, 23, 97, 106]);
  expect(code128('0').values).toEqual([104, 16, 17, 106]);
  for (const code of ['', 'दूध', '\nA', '\u007f', 'a'.repeat(101)])
    expect(() => code128(code)).toThrow();
});

it('paginates exact copies and rejects unreadable or physically impossible layouts', () => {
  const row = {
    item_id: '5',
    name: 'दूध',
    code: '00000123',
    copies: 22,
    price_paisa: '12345',
    unit_label: 'kg',
    tax_paisa: '0',
  };
  const layout = labelPages([row], DEFAULT_LABEL_FORMAT);
  expect(layout.total).toBe(22);
  expect(layout.pages.map((page) => page.length)).toEqual([21, 1]);
  expect(layout.pages[0][0].barcode?.width).toBe(99);
  for (const format of [
    { ...DEFAULT_LABEL_FORMAT, columns: 4 },
    { ...DEFAULT_LABEL_FORMAT, moduleWidth: 0.1 },
    { ...DEFAULT_LABEL_FORMAT, height: 15 },
    { ...DEFAULT_LABEL_FORMAT, gapX: NaN },
  ])
    expect(() => labelPages([row], format)).toThrow();
  expect(() => labelPages([{ ...row, code: 'a'.repeat(40) }], DEFAULT_LABEL_FORMAT)).toThrow(
    /code/i,
  );
  expect(() => labelPages([{ ...row, copies: 1001 }], DEFAULT_LABEL_FORMAT)).toThrow();
  expect(
    labelPages([{ ...row, code: null, copies: 1 }], { ...DEFAULT_LABEL_FORMAT, showCode: false })
      .total,
  ).toBe(1);
});
