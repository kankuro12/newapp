export const digits = (text: string) =>
  text.trim().replace(/[०-९]/g, (digit) => String('०१२३४५६७८९'.indexOf(digit)));
function parse(text: string, places: number, cap = 1_000_000_000n): bigint {
  const normalized = digits(text);
  if (!new RegExp(`^\\d+(?:\\.\\d{1,${places}})?$`).test(normalized))
    throw new Error(`Use positive numbers with at most ${places} decimal places.`);
  const [whole, fraction = ''] = normalized.split('.');
  const result = BigInt(whole + fraction.padEnd(places, '0'));
  if (result > cap) throw new Error('Amount exceeds supported limit.');
  return result;
}
export const amount = (text: string) => parse(text, 2);
export const quantity = (text: string) => parse(text, 3);
export function format(value: string | bigint, places = 2): string {
  const integer = BigInt(value || '0');
  const absolute = (integer < 0n ? -integer : integer).toString().padStart(places + 1, '0');
  return `${integer < 0n ? '-' : ''}${absolute.slice(0, -places)}.${absolute.slice(-places)}`;
}
export function currency(value: string | bigint = '0'): string {
  const [whole, fraction] = format(value).split('.');
  return `रु ${whole.startsWith('-') ? '-' : ''}${BigInt(whole.replace(/^-/, '')).toLocaleString('en-IN')}.${fraction}`;
}
export function round(a: bigint, b: bigint, divisor: bigint): bigint {
  const product = a * b;
  const absolute = product < 0n ? -product : product;
  return (
    (absolute / divisor + ((absolute % divisor) * 2n >= divisor ? 1n : 0n)) *
    (product < 0n ? -1n : 1n)
  );
}
export interface PreviewLine {
  qty: string;
  unit_price: string;
  discount?: string;
  discount_bps?: string;
  tax_bps?: string;
  tax_category?: string;
}
export function calculate(rows: PreviewLine[], billDiscount = '0', billDiscountBps?: string) {
  const lines = rows.map((row) => {
    const gross = round(quantity(row.qty), amount(row.unit_price), 1000n);
    const discount = row.discount_bps
      ? round(gross, BigInt(row.discount_bps), 10000n)
      : amount(row.discount || '0');
    if (discount > gross) throw new Error('Discount exceeds price.');
    return { gross, discount, base: gross - discount, invoiceDiscount: 0n, tax: 0n, total: 0n };
  });
  const base = lines.reduce((sum, line) => sum + line.base, 0n);
  const discount =
    billDiscountBps === undefined
      ? amount(billDiscount || '0')
      : round(base, BigInt(billDiscountBps), 10000n);
  if (discount > base) throw new Error('Bill discount exceeds subtotal.');
  const remainders = lines.map((line, index) => ({
    index,
    remainder: base ? (discount * line.base) % base : 0n,
  }));
  lines.forEach((line) => {
    line.invoiceDiscount = base ? (discount * line.base) / base : 0n;
  });
  remainders.sort((a, b) =>
    a.remainder === b.remainder ? a.index - b.index : a.remainder > b.remainder ? -1 : 1,
  );
  const left = discount - lines.reduce((sum, line) => sum + line.invoiceDiscount, 0n);
  for (let i = 0; BigInt(i) < left; i++) lines[remainders[i].index].invoiceDiscount++;
  lines.forEach((line, i) => {
    line.base -= line.invoiceDiscount;
    line.tax = ['standard', 'zero'].includes(rows[i].tax_category || '')
      ? round(line.base, BigInt(rows[i].tax_bps || '0'), 10000n)
      : 0n;
    line.total = line.base + line.tax;
  });
  return {
    lines,
    invoiceDiscount: discount,
    subtotal: lines.reduce((sum, line) => sum + line.gross, 0n),
    tax: lines.reduce((sum, line) => sum + line.tax, 0n),
    total: lines.reduce((sum, line) => sum + line.total, 0n),
  };
}
export const bsDisplay = (date: number | string) =>
  String(date).replace(/^(\d{4})(\d{2})(\d{2})$/, '$1-$2-$3');
