import { useState } from 'react';
import { IonButton } from '@ionic/react';
import { Link, useNavigate } from 'react-router-dom';
import { useWorkspace } from '../lib/context';
import { useData, useSave } from '../screen-fit-data';
import { bsDisplay, currency, format } from '../lib/money';
import type { Lookup, Page, Party } from '../lib/types';
import { Check, Empty, Errors, Field, Heading, Loading, OptionalDetails, Select, Status, Submit } from '../components/ui';
import Picker from '../components/Picker';
import EntrySteps from '../components/EntrySteps';
import { Pagination } from './Records';

type Kind = 'salary' | 'rent' | 'other';
interface Period { document_id: string | null; number?: string; status: string; total_paisa: string; due_paisa: string; period_bs?: number }
interface Regular { id: string; kind: Kind; label: string; contact_id: string; payee_name: string; amount_paisa: string; first_date_bs: number; next_date_bs: number; monthly_day: number; enabled: boolean; auto_generate: boolean; version: number; last_error: string | null; current_month: Period }
const kinds: [Kind, string][] = [['salary', 'Employees'], ['rent', 'Rent'], ['other', 'Other']];

export default function RegularPayments() {
  const { base, revision, business, t } = useWorkspace();
  const [kind, setKind] = useState<Kind>('salary'); const [page, setPage] = useState(1); const [selected, setSelected] = useState<string>(); const [adding, setAdding] = useState(false);
  const list = useData<Page<Regular>>(business.role === 'cashier' ? null : `${base}/regular-expenses?kind=${kind}&page=${page}`, revision);
  if (business.role === 'cashier') return <Empty title="Page unavailable" />;
  if (selected) return <RegularDetail key={selected} id={selected} back={() => setSelected(undefined)} />;
  const addLabel = kind==='salary'?'Add employee':kind==='rent'?'Add rent':'Add regular bill';
  return <div className="regular-tools"><Heading title={t(adding?addLabel:'Regular payments')} description={adding?undefined:t('Salary, rent and regular bills.')}><IonButton onClick={()=>setAdding(!adding)}>{t(adding?'Back':addLabel)}</IonButton></Heading>
    {adding ? <Setup key={kind} kind={kind} done={row=>{setAdding(false);setSelected(row.id);}}/> : <>
      <div className="payment-tabs" role="group" aria-label="Regular payment type">{kinds.map(([value,label])=><button type="button" key={value} className={kind===value?'active':''} onClick={()=>{setKind(value);setPage(1);}}>{t(label)}</button>)}</div>
      <section className="panel">{list.loading||list.error ? <Loading error={list.error} retry={list.reload}/> : list.data?.data.length ? <>{list.data.data.map(row=><div className="master-row" key={row.id}><div className="master-name"><strong>{row.payee_name}</strong><small>{row.label} · {currency(row.amount_paisa)} / month</small><small>Next {bsDisplay(row.next_date_bs)} BS · {row.enabled?row.auto_generate?'Automatic dues':'Manual':'Paused'}</small>{row.last_error&&<p role="alert" className="error-box">{row.last_error}</p>}</div><div className="master-right"><strong>{currency(row.current_month.document_id?row.current_month.due_paisa:'0')}</strong><small>{row.current_month.document_id?'This month remaining':'This month unrecorded'}</small><IonButton size="small" fill="outline" onClick={()=>setSelected(row.id)}>{t('Open')}</IonButton></div></div>)}<Pagination page={page} pages={list.data.last_page} change={setPage}/></> : <Empty title={t('No entries yet')} action={t(addLabel)} onClick={()=>setAdding(true)}/>}</section>
    </>}
  </div>;
}

function Setup({ kind, row, done }: { kind: Kind; row?: Regular; done: (row: Regular) => void }) {
  const { base, today, changed, revision, t } = useWorkspace(); const form = useSave();
  const [label, setLabel] = useState(row?.label || (kind === 'salary' ? 'Monthly salary' : kind === 'rent' ? 'Monthly rent' : 'Monthly bill'));
  const [name, setName] = useState(''); const [party, setParty] = useState<Party>(); const [existing, setExisting] = useState(false);
  const [amount, setAmount] = useState(row ? format(row.amount_paisa) : ''); const [first, setFirst] = useState(bsDisplay(today)); const [day, setDay] = useState(String(today % 100));
  const [automatic, setAutomatic] = useState(row?.auto_generate ?? true); const [enabled, setEnabled] = useState(row?.enabled ?? true); const [category, setCategory] = useState('');
  const lookup = useData<{ data: Lookup }>(kind === 'other' && !row ? `${base}/lookup` : null, revision);
  const amountStep = row?0:1; const scheduleStep=row?1:2;
  return <div className={`regular-setup ${row?'regular-edit':''}`}><EntrySteps labels={(row?['Amount','Schedule']:['Payee','Amount','Schedule']).map(t)} dirty busy={form.busy} error={form.error} t={t} canContinue={row?[!!amount.trim()]:[existing?!!party:!!name.trim(),!!amount.trim()]} onSubmit={e=>{e.preventDefault();const input={label,amount,auto_generate:automatic,enabled,...(row?{version:row.version}:{kind,contact_id:existing?party?.id:null,payee_name:existing?null:name,first_date_bs:first,monthly_day:day,...(kind==='other'?{expense_category_id:category||lookup.data?.data.categories[0]?.id}:{})})};void form.save<Regular>(`${base}/regular-expenses${row?'/'+row.id:''}`,input,value=>{changed();done(value);},row?'PATCH':'POST');}}>
    <Errors error={form.error||lookup.error}/><div className="form-main">
      {!row && <section className="panel" data-entry-step="0"><h2>{t('Payee')}</h2><Check checked={existing} onChange={value=>{setExisting(value);setParty(undefined);}}>{t('Choose existing party')}</Check>{existing ? <>{party ? <div className="selected-party"><strong>{party.name}</strong><IonButton fill="clear" onClick={()=>setParty(undefined)}>{t('Change')}</IonButton></div> : <Picker kind="contacts" role={kind==='salary'?'employee':kind==='rent'?'rent':undefined} payable={kind==='other'} onPick={value=>setParty(value as Party)}/>}<p className="subtle">{t('Party can have several roles. Add matching role in Parties.')}</p></> : <Field label={t(kind==='salary'?'Employee name':'Payee name')} name="payee_name" value={name} onChange={e=>setName(e.target.value)} required maxLength={150}/>}</section>}
      <section className="panel" data-entry-step={amountStep}><h2>{t('Amount')}</h2><Field label={t(kind==='salary'?'Monthly salary (NPR)':kind==='rent'?'Monthly rent (NPR)':'Monthly amount (NPR)')} name="amount" inputMode="decimal" value={amount} onChange={e=>setAmount(e.target.value)} required/>{kind==='other'&&!row&&<Select label={t('Category')} name="expense_category_id" value={category||lookup.data?.data.categories[0]?.id||''} onChange={setCategory}>{lookup.data?.data.categories.map(value=><option key={value.id} value={value.id}>{value.name}</option>)}</Select>}
        <OptionalDetails label={t('Additional setup')}><Field label={t('Payment label')} name="label" value={label} onChange={e=>setLabel(e.target.value)} required maxLength={150}/><Check checked={enabled} onChange={setEnabled}>{t('Enabled')}</Check><p className="subtle">{t('Monthly BS dates. Short months use last day; amount remains full monthly amount.')}</p><p className="subtle">{t('Automatic action records unpaid expense on due date. Money leaves cash/bank only when you pay. Pausing keeps existing dues; resuming catches up missed months.')}</p>{row&&<p className="subtle">{t('Amount changes affect future unrecorded months. Recorded expenses keep original amount.')}</p>}</OptionalDetails>
      </section>
      <section className="panel" data-entry-step={scheduleStep}><h2>{t('Schedule')}</h2><p className="regular-review">{row?.payee_name||party?.name||name} · {amount} NPR · {t(enabled?'Enabled':'Paused')}</p>{!row&&<div className="form-grid regular-date-row"><Field label={t('First expense date (BS)')} name="first_date_bs" value={first} onChange={e=>setFirst(e.target.value)} required/><Select label={t('Following months: day')} name="monthly_day" value={day} onChange={setDay}>{Array.from({length:32},(_,i)=><option key={i+1} value={i+1}>{i===31?t('Month end'):i+1}</option>)}</Select></div>}<Check checked={automatic} onChange={setAutomatic}>{t('Automatically record monthly expense + payable')}</Check><p className="subtle">{t('Unpaid dues only. Pay separately.')}</p><div className="regular-submit"><Submit busy={form.busy} disabled={!row&&existing&&!party}>{t('Save')}</Submit></div></section>
    </div>
  </EntrySteps></div>;
}
function RegularDetail({ id, back }: { id: string; back: () => void }) {
  const { base, path, revision, t } = useWorkspace(); const rule = useData<{ data: Regular }>(`${base}/regular-expenses/${id}`, revision); const [edit, setEdit] = useState(false); const [page, setPage] = useState(1); const history = useData<Page<Period>>(`${base}/regular-expenses/${id}/history?page=${page}`, revision);
  if (rule.loading || rule.error || !rule.data) return <Loading error={rule.error} retry={rule.reload} />;
  const row = rule.data.data;
  return <div className="regular-tools"><Heading title={row.payee_name} description={`${row.label} · ${currency(row.amount_paisa)} / month`}><IonButton fill="outline" onClick={back}>{t('Back')}</IonButton><IonButton fill="clear" onClick={() => setEdit(!edit)}>{edit ? 'Close setup' : 'Edit / pause'}</IonButton></Heading>
    {row.last_error && <Errors error={new Error(row.last_error)} />}
    {edit ? <Setup key={row.version} kind={row.kind} row={row} done={() => setEdit(false)} /> : <RecordMonth row={row} />}
    {!edit && <OptionalDetails label={t('Monthly history')}><section className="panel">{history.loading || history.error ? <Loading error={history.error} retry={history.reload} /> : history.data?.data.length ? <>{history.data.data.map(period => <div className="mini-row" key={period.document_id}><span>{bsDisplay(period.period_bs || 0).slice(0, 7)} BS · <Status value={period.status} /></span><span>{currency(period.due_paisa)} remaining · <Link to={`${path}/document/${period.document_id}`}>{period.number}</Link></span></div>)}<Pagination page={page} pages={history.data.last_page} change={setPage} /></> : <Empty title="No monthly expenses yet" />}</section></OptionalDetails>}
  </div>;
}

function RecordMonth({ row }: { row: Regular }) {
  const { base, path, today, changed, revision, business, t } = useWorkspace(); const navigate = useNavigate(); const form = useSave();
  const [month, setMonth] = useState(bsDisplay(row.current_month.document_id ? today : row.next_date_bs).slice(0, 7)); const [date, setDate] = useState(bsDisplay(today)); const [mode, setMode] = useState('later'); const [paid, setPaid] = useState(''); const [account, setAccount] = useState(''); const [overdraft, setOverdraft] = useState(false);
  const preview = useData<{ data: Period }>(`${base}/regular-expenses/${row.id}/period?period_bs=${encodeURIComponent(month + '-01')}`, revision); const lookup = useData<{ data: Lookup }>(`${base}/lookup`, revision); const current = preview.data?.data; const accounts = lookup.data?.data.accounts || [];
  return <form className="panel narrow-form" data-dirty="true" onSubmit={e => { e.preventDefault(); if (!current) return; void form.save<{ id: string }>(`${base}/regular-expenses/${row.id}/post`, { period_bs: month + '-01', business_date_bs: date, expected_total_paisa: current.total_paisa, paid_now: mode === 'later' ? '0' : mode === 'full' ? format(current.due_paisa) : paid, money_account_id: mode === 'later' ? null : account || accounts[0]?.id, overdraft_confirmed: overdraft }, doc => { changed(); navigate(`${path}/document/${doc.id}`); }); }}>
    <h2>{t(row.kind === 'salary' ? 'Pay salary / record month' : row.kind === 'rent' ? 'Pay rent / record month' : 'Record monthly bill')}</h2><Errors error={form.error || preview.error || lookup.error} />
    <div className="form-grid"><Field label={t('Month (BS YYYY-MM)')} value={month} onChange={e => { setMonth(e.target.value); setPaid(''); }} required placeholder="2083-06" /><Field label={t('Business date (BS)')} name="business_date_bs" value={date} onChange={e => setDate(e.target.value)} required /></div>
    {current && <><div className="mini-row"><span>{current.document_id ? 'Recorded expense' : 'Monthly expense'}</span><strong>{currency(current.total_paisa)}</strong></div><div className="mini-row"><span>{t('Still to pay')}</span><strong>{currency(current.due_paisa)}</strong></div>{current.document_id && <Link to={`${path}/document/${current.document_id}`}>{current.number} · {current.status}</Link>}</>}
    <Select label="Payment" value={mode} onChange={setMode}><option value="later">{t('Pay later')}</option><option value="full">{t('Pay remaining now')}</option><option value="partial">{t('Part paid')}</option></Select>
    {mode !== 'later' && <>{mode === 'partial' && <Field label={t('Paid now')} name="paid_now" inputMode="decimal" value={paid} onChange={e => setPaid(e.target.value)} required />}<Select label={t('Payment account')} value={account || accounts[0]?.id || ''} onChange={setAccount}>{accounts.map(value => <option key={value.id} value={value.id}>{value.name}</option>)}</Select>{['owner','accountant'].includes(business.role) && <details><summary>Bank overdraft</summary><Check checked={overdraft} onChange={setOverdraft}>Confirm bank overdraft if needed</Check></details>}</>}
    <p className="subtle">One expense per setup per month. Paying existing month updates its dues; it adds no second expense.</p><Submit busy={form.busy} disabled={!current || preview.loading || current.status === 'cancelled' || (mode !== 'later' && current.due_paisa === '0')}>{t(mode === 'later' ? 'Record expense + payable' : 'Pay now + record expense')}</Submit>
  </form>;
}
