// ISO/IEC 15417 symbol widths, verified against Zint's BSD-3-Clause table.
// Attribution/license: THIRD-PARTY-NOTICES.md. Encoder below is local code.
const patterns='212222 222122 222221 121223 121322 131222 122213 122312 132212 221213 221312 231212 112232 122132 122231 113222 123122 123221 223211 221132 221231 213212 223112 312131 311222 321122 321221 312212 322112 322211 212123 212321 232121 111323 131123 131321 112313 132113 132311 211313 231113 231311 112133 112331 132131 113123 113321 133121 313121 211331 231131 213113 213311 213131 311123 311321 331121 312113 312311 332111 314111 221411 431111 111224 111422 121124 121421 141122 141221 112214 112412 122114 122411 142112 142211 241211 221114 413111 241112 134111 111242 121142 121241 114212 124112 124211 411212 421112 421211 212141 214121 412121 111143 111341 131141 114113 114311 411113 411311 113141 114131 311141 411131 211412 211214 211232 2331112'.split(' ');
export interface Barcode {values:number[];bars:{x:number;width:number}[];width:number}
export function code128(code:string):Barcode {
  if(!/^[ -~]{1,100}$/.test(code))throw new Error('Code128 needs 1–100 printable ASCII characters. Choose an alternate code or no barcode.');
  // ponytail: mixed identifiers use B; add B/C switching if real label widths require it.
  const numeric=/^\d+$/.test(code)&&code.length%2===0;
  const values=numeric?[105,...code.match(/../g)!.map(Number)]:[104,...Array.from(code,char=>char.charCodeAt(0)-32)];
  values.push(values.reduce((sum,value,index)=>sum+value*(index||1),0)%103,106);
  let x=10;const bars:Barcode['bars']=[];
  for(const value of values)for(const [index,digit] of Array.from(patterns[value]).entries()){const width=Number(digit);if(index%2===0)bars.push({x,width});x+=width;}
  return {values,bars,width:x+10};
}
export interface LabelFormat {paperWidth:number;paperHeight:number;width:number;height:number;columns:number;margin:number;gapX:number;gapY:number;moduleWidth:number;barHeight:number;showName:boolean;showPrice:boolean;showCode:boolean;includeTax:boolean}
export const DEFAULT_LABEL_FORMAT:LabelFormat={paperWidth:210,paperHeight:297,width:60,height:35,columns:3,margin:8,gapX:3,gapY:2,moduleWidth:0.25,barHeight:10,showName:true,showPrice:true,showCode:true,includeTax:true};
export interface LabelRow {item_id:string;name:string;code:string|null;copies:number;price_paisa:string;unit_label:string;tax_paisa:string}
export interface PrintedLabel extends LabelRow {barcode?:Barcode}
export function labelPages(rows:LabelRow[],format:LabelFormat):{pages:PrintedLabel[][];total:number;rows:number} {
  const limits:Record<string,[number,number]>={paperWidth:[50,300],paperHeight:[25,500],width:[20,150],height:[15,100],columns:[1,5],margin:[0,30],gapX:[0,20],gapY:[0,20],moduleWidth:[0.2,0.5],barHeight:[8,50]};
  for(const [key,[min,max]] of Object.entries(limits)){const value=format[key as keyof LabelFormat];if(typeof value!=='number'||!Number.isFinite(value)||value<min||value>max)throw new Error('Paper / label settings outside supported limits.');}
  for(const key of ['showName','showPrice','showCode','includeTax'] as const)if(typeof format[key]!=='boolean')throw new Error('Invalid label display setting.');
  if(!Number.isInteger(format.columns)||format.columns*format.width+(format.columns-1)*format.gapX>format.paperWidth-2*format.margin)throw new Error('Label columns do not fit paper width.');
  const perColumn=Math.floor((format.paperHeight-2*format.margin+format.gapY)/(format.height+format.gapY));
  if(perColumn<1)throw new Error('Label height does not fit paper.');
  if(!rows.length||rows.length>100||rows.some(row=>!Number.isSafeInteger(row.copies)||row.copies<1||row.copies>1000))throw new Error('Choose up to 100 items and 1–1000 copies each.');
  const total=rows.reduce((sum,row)=>sum+row.copies,0);if(total>1000)throw new Error('Print at most 1000 labels per batch.');
  const labels=rows.flatMap(row=>{
    const barcode=row.code===null?undefined:code128(row.code);
    if(barcode&&barcode.width*format.moduleWidth>format.width-4)throw new Error('Product code too wide. Choose a shorter alternate code or wider label.');
    const sections=Number(format.showName)+Number(format.showPrice)+Number(!!barcode)+Number(format.showCode&&!!barcode);
    if(!sections)throw new Error('Label needs a name, price or barcode.');
    const needed=4+(format.showName?6:0)+(format.showPrice?3:0)+(barcode?format.barHeight:0)+(format.showCode&&barcode?3:0)+Math.max(0,sections-1);
    if(needed>format.height)throw new Error('Label too short for selected text and barcode height.');
    return Array.from({length:row.copies},()=>({...row,barcode}));
  });
  const capacity=perColumn*format.columns;const pages:PrintedLabel[][]=[];
  for(let offset=0;offset<labels.length;offset+=capacity)pages.push(labels.slice(offset,offset+capacity));
  return {pages,total,rows:perColumn};
}
