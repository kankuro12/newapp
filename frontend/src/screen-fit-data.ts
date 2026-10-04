const rule={id:'1',kind:'salary',payee_name:'Public sample',label:'Monthly salary',amount_paisa:'1000000',next_date_bs:20830103,first_date_bs:20830103,monthly_day:3,enabled:true,auto_generate:true,version:1,current_month:{document_id:null}};
export function useData<T>(path:string|null,revision?:number) {
  void revision;
  const data = path?.includes('/lookup') ? {accounts:[{id:'1',name:'Cash'}],categories:[{id:'3',name:'Power'}]} : path?.includes('/period?') ? {document_id:null,status:'planned',total_paisa:'1000000',due_paisa:'1000000'} : path?.includes('/regular-expenses/1/history') ? [] : path?.includes('/regular-expenses/1') ? rule : path?.includes('/regular-expenses?') ? [rule] : [{id:'9',kind:'receipt',status:'posted',amount_paisa:'10025',business_date_bs:20830103}];
  return {data:path?{data,last_page:1} as T:undefined,loading:false,error:undefined,reload:()=>{}};
}
export function useSave() {
  return {busy:false,uncertain:false,error:undefined,clear:()=>{},retry:()=>Promise.reject(new Error('Posting disabled in fixture')),save:<T>(path:string,input:Record<string,unknown>,success:(data:T)=>void,method?:string)=>{void path;void input;void success;void method;throw new Error('Posting disabled in fixture');}};
}
export async function request<T>(path:string, options?:RequestInit):Promise<T> {void path;void options;throw new Error('Requests disabled in fixture');}