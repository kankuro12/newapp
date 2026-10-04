export function useData<T>() {
  const home = {sales_paisa:'120050',cash_paisa:'340099',receivables_paisa:'10025',payables_paisa:'24000',recent:Array.from({length:7},(_,i)=>({id:String(i+1),number:'SALE-'+(i+1),type:'sale',party_snapshot:{name:'Customer '+(i+1)},business_date_bs:20830103,total_paisa:'10000',status:'posted'})),low_stock:Array.from({length:7},(_,i)=>({id:String(i+1),name:'Item '+(i+1),qty_milli:'1250',unit_label:'kg'}))};
  return {data:{data:home} as T,error:undefined,loading:false,reload:()=>{}};
}