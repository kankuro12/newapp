import { IonButton } from '@ionic/react';
import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useWorkspace } from '../lib/context';
import { useData, useSave } from '../lib/api';
import { bsDisplay, currency, format } from '../lib/money';
import type { Item, Page, Party } from '../lib/types';
import Picker from '../components/Picker';
import {
  Check,
  Empty,
  Errors,
  Field,
  Heading,
  Loading,
  Select,
  Status,
  Submit,
} from '../components/ui';
import { Pagination } from './Records';
import { PartyListAssignments } from './PriceLists';
interface Followup {
  id: string;
  contact_id: string;
  document_id?: string;
  bill_number?: string;
  bill_status?: string;
  party_name: string;
  phone?: string;
  title: string;
  due_date_bs: number;
  channel: string;
  assigned_to: string;
  assigned_name: string;
  status: string;
  version: number;
  overdue: boolean;
}
interface Rate {
  id: string;
  item_id: string;
  item_name: string;
  channel: string;
  price_paisa: string;
  enabled: boolean;
  version: number;
  unit_snapshot: string;
}
function Retry({ form }: { form: ReturnType<typeof useSave> }) {
  const { t } = useWorkspace();
  return form.uncertain ? (
    <IonButton disabled={form.busy} onClick={() => void form.retry()}>
      {t('Retry original action')}
    </IonButton>
  ) : null;
}
export function Collections() {
  const { base, path, revision, t } = useWorkspace();
  const [channel, setChannel] = useState('receivables');
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const rows = useData<
    Page<{ id: string; name: string; phone?: string; due_paisa: string; archived_at?: string }>
  >(`${base}/collections?channel=${channel}&q=${encodeURIComponent(query)}&page=${page}`, revision);
  return (
    <>
      <Heading
        title={t('Collections / payments')}
        description={t('Net party balances include starting amounts, returns and unapplied money.')}
      >
        <Link to={path + '/followups'}>{t('Follow-ups')}</Link>
        <Link
          to={
            path +
            '/reports?report=' +
            (channel === 'receivables' ? 'customer-aging' : 'supplier-aging')
          }
        >
          {t('Aging')}
        </Link>
      </Heading>
      <div className="list-tools">
        <Select
          label={t('Money direction')}
          value={channel}
          onChange={(value) => {
            setChannel(value);
            setPage(1);
          }}
        >
          <option value="receivables">{t('Customers owe us')}</option>
          <option value="payables">{t('We owe parties')}</option>
        </Select>
        <Field
          label={t('Search party / phone')}
          value={query}
          onChange={(e) => {
            setQuery(e.target.value);
            setPage(1);
          }}
        />
      </div>
      {rows.loading || rows.error ? (
        <Loading error={rows.error} retry={rows.reload} />
      ) : rows.data?.data.length ? (
        <section className="panel">
          {rows.data.data.map((row) => (
            <article className="collection-row" key={row.id}>
              <div>
                <Link to={path + '/contacts/' + row.id}>
                  <strong>{row.name}</strong>
                </Link>
                <small>
                  {row.phone}
                  {row.archived_at ? ' · ' + t('Archived') : ''}
                </small>
              </div>
              <strong>{currency(row.due_paisa)}</strong>
              <div className="detail-actions">
                <Link
                  to={`${path}/money/${channel === 'receivables' ? 'receipt' : 'supplier_payment'}/new?contact=${row.id}&amount=${format(row.due_paisa)}`}
                >
                  {t(channel === 'receivables' ? 'Receive money' : 'Pay party')}
                </Link>
                <Link to={`${path}/followups?contact=${row.id}&new=1`}>{t('Add follow-up')}</Link>
                <Link to={`${path}/reports?report=statement&contact=${row.id}`}>
                  {t('View statement')}
                </Link>
              </div>
            </article>
          ))}
          <Pagination page={page} pages={rows.data.last_page} change={setPage} />
        </section>
      ) : (
        <Empty title={t('No outstanding balance')} />
      )}
    </>
  );
}
export function Followups() {
  const { base, path, revision, t, today, changed } = useWorkspace();
  const [params] = useSearchParams();
  const contactId = params.get('contact');
  const documentId = params.get('document');
  const initial = useData<{ data: Party }>(contactId ? base + '/contacts/' + contactId : null);
  const [party, setParty] = useState<Party>();
  const [adding, setAdding] = useState(params.get('new') === '1');
  const [status, setStatus] = useState('open');
  const [dueOnly, setDueOnly] = useState(true);
  const [mine, setMine] = useState(false);
  const [page, setPage] = useState(1);
  const [title, setTitle] = useState('');
  const [date, setDate] = useState(bsDisplay(today));
  const [channel, setChannel] = useState('phone');
  const [assigned, setAssigned] = useState('');
  const [notes, setNotes] = useState('');
  const form = useSave();
  const navigate = useNavigate();
  const rows = useData<Page<Followup> & { staff: { id: string; name: string }[] }>(
    `${base}/followups?status=${status}&due=${dueOnly ? 1 : 0}&mine=${mine ? 1 : 0}&contact_id=${contactId || ''}&page=${page}`,
    revision,
  );
  useEffect(() => {
    if (initial.data) setParty(initial.data.data);
  }, [initial.data]);
  return (
    <>
      <Heading
        title={t('Follow-ups')}
        description={t('Plan a call or visit; record what happened. No message is sent.')}
      >
        <Link to={path + '/collections'}>{t('Collections / payments')}</Link>
        <IonButton onClick={() => setAdding(!adding)}>
          {t(adding ? 'Close' : 'Add follow-up')}
        </IonButton>
      </Heading>
      {adding && (
        <form
          className="panel narrow-form"
          data-dirty={title ? 'true' : 'false'}
          onSubmit={(e) => {
            e.preventDefault();
            void form.save<Followup>(
              base + '/followups',
              {
                contact_id: party?.id,
                document_id: documentId || null,
                title,
                due_date_bs: date,
                channel,
                assigned_to: assigned || rows.data?.staff[0]?.id,
                notes: notes || null,
              },
              (row) => {
                changed();
                navigate(path + '/followup/' + row.id);
              },
            );
          }}
        >
          <Errors error={form.error || initial.error} />
          <Retry form={form} />
          {party ? (
            <div className="selected-party">
              <strong>{party.name}</strong>
              {!documentId && (
                <IonButton fill="clear" onClick={() => setParty(undefined)}>
                  {t('Change')}
                </IonButton>
              )}
            </div>
          ) : (
            <Picker kind="contacts" onPick={(row) => setParty(row as Party)} />
          )}
          <Field
            label={t('Follow-up title')}
            maxLength={150}
            required
            value={title}
            onChange={(e) => setTitle(e.target.value)}
          />
          <Field
            label={t('Follow-up day (BS)')}
            value={date}
            required
            inputMode="numeric"
            onChange={(e) => setDate(e.target.value)}
          />
          <Select label={t('Contact method')} value={channel} onChange={setChannel}>
            {['phone', 'visit', 'message', 'note'].map((value) => (
              <option key={value}>{value}</option>
            ))}
          </Select>
          <Select
            label={t('Assigned to')}
            value={assigned || rows.data?.staff[0]?.id || ''}
            onChange={setAssigned}
          >
            {rows.data?.staff.map((row) => (
              <option key={row.id} value={row.id}>
                {row.name}
              </option>
            ))}
          </Select>
          <Field
            label={t('Notes')}
            value={notes}
            maxLength={1000}
            onChange={(e) => setNotes(e.target.value)}
          />
          {documentId && <Link to={path + '/document/' + documentId}>{t('Open bill')}</Link>}
          <Submit
            busy={form.busy}
            disabled={!party || form.uncertain || !!rows.error || !rows.data?.staff.length}
          >
            {t('Save follow-up')}
          </Submit>
        </form>
      )}
      <div className="list-tools">
        <Select
          label={t('Status')}
          value={status}
          onChange={(value) => {
            setStatus(value);
            setPage(1);
          }}
        >
          {['open', 'done', 'cancelled', 'all'].map((value) => (
            <option key={value}>{value}</option>
          ))}
        </Select>
        <Check
          checked={dueOnly}
          onChange={(value) => {
            setDueOnly(value);
            setPage(1);
          }}
        >
          {t('Due today or overdue')}
        </Check>
        <Check
          checked={mine}
          onChange={(value) => {
            setMine(value);
            setPage(1);
          }}
        >
          {t('Assigned to me')}
        </Check>
      </div>
      {rows.loading || rows.error ? (
        <Loading error={rows.error} retry={rows.reload} />
      ) : rows.data?.data.length ? (
        <section className="panel">
          {rows.data.data.map((row) => (
            <Link className="record-row" key={row.id} to={path + '/followup/' + row.id}>
              <div>
                <strong>{row.title}</strong>
                <small>
                  {row.party_name} · {row.phone} · {row.assigned_name}
                </small>
              </div>
              <span>
                {bsDisplay(row.due_date_bs)} BS{' '}
                {row.overdue && <small className="error-text">{t('Overdue')}</small>}
              </span>
              <Status value={row.status} />
            </Link>
          ))}
          <Pagination page={page} pages={rows.data.last_page} change={setPage} />
        </section>
      ) : (
        <Empty title={t('No follow-ups due')} />
      )}
    </>
  );
}
export function FollowupDetail() {
  const { id = '' } = useParams();
  const { base, path, revision, changed, t } = useWorkspace();
  const [page, setPage] = useState(1);
  const result = useData<{
    data: Followup;
    history: Page<{
      id: string;
      action: string;
      notes?: string;
      business_date_bs: number;
      created_at: string;
    }>;
  }>(`${base}/followups/${id}?page=${page}`, revision);
  const team = useData<Page<Followup> & { staff: { id: string; name: string }[] }>(
    base + '/followups',
  );
  const [date, setDate] = useState('');
  const [assigned, setAssigned] = useState('');
  const [notes, setNotes] = useState('');
  const form = useSave();
  if (result.loading || result.error) return <Loading error={result.error} retry={result.reload} />;
  const row = result.data!.data;
  function save(status: string) {
    void form.save(
      `${base}/followups/${id}`,
      {
        version: row.version,
        status,
        due_date_bs: date || bsDisplay(row.due_date_bs),
        assigned_to: assigned || row.assigned_to,
        notes: notes || null,
      },
      () => {
        setNotes('');
        changed();
      },
      'PATCH',
    );
  }
  return (
    <>
      <Heading title={row.title}>
        <Status value={row.status} />
        <Link to={path + '/followups'}>{t('Back')}</Link>
      </Heading>
      <section className="panel narrow-form">
        <Link to={path + '/contacts/' + row.contact_id}>
          <h2>{row.party_name}</h2>
        </Link>
        <p>
          {row.phone} · {row.channel} · {row.assigned_name}
        </p>
        <p>
          {bsDisplay(row.due_date_bs)} BS{' '}
          {row.overdue && <strong className="error-text">{t('Overdue')}</strong>}
        </p>
        {row.document_id && (
          <Link to={path + '/document/' + row.document_id}>
            {row.bill_number} · {row.bill_status}
          </Link>
        )}
        <Errors error={form.error || team.error} />
        <Retry form={form} />
        <Field
          label={t('Next follow-up day (BS)')}
          value={date || bsDisplay(row.due_date_bs)}
          inputMode="numeric"
          onChange={(e) => setDate(e.target.value)}
        />
        <Select label={t('Assigned to')} value={assigned || row.assigned_to} onChange={setAssigned}>
          {team.data?.staff.map((person) => (
            <option key={person.id} value={person.id}>
              {person.name}
            </option>
          ))}
        </Select>
        <label className="field">
          <span>{t('What happened / next action')}</span>
          <textarea
            value={notes}
            maxLength={1000}
            rows={3}
            onChange={(e) => setNotes(e.target.value)}
          />
        </label>
        <div className="detail-actions">
          {['open', 'done', 'cancelled'].map((status) => (
            <IonButton
              key={status}
              disabled={form.busy || form.uncertain || !!team.error || !notes.trim()}
              fill={status === 'open' ? 'solid' : 'outline'}
              onClick={() => save(status)}
            >
              {t(
                status === 'open'
                  ? row.status === 'open'
                    ? 'Save next action'
                    : 'Reopen'
                  : status === 'done'
                    ? 'Mark done'
                    : 'Cancel',
              )}
            </IonButton>
          ))}
        </div>
        <small>{t('Recording a follow-up does not settle any balance.')}</small>
      </section>
      <section className="panel narrow-form">
        <h2>{t('Follow-up history')}</h2>
        {result.data?.history.data.map((event) => (
          <article className="followup-event" key={event.id}>
            <strong>
              {event.action} · {bsDisplay(event.business_date_bs)} BS
            </strong>
            <p>{event.notes}</p>
            <small>{event.created_at} UTC</small>
          </article>
        ))}
        <Pagination page={page} pages={result.data!.history.last_page} change={setPage} />
      </section>
    </>
  );
}
export function PartyTrading() {
  const { id = '' } = useParams();
  const { base, path, revision, changed, t } = useWorkspace();
  const result = useData<{ data: Party }>(`${base}/contacts/${id}/trading`, revision);
  const [page, setPage] = useState(1);
  const rates = useData<Page<Rate>>(`${base}/contacts/${id}/rates?page=${page}`, revision);
  const [limit, setLimit] = useState('');
  const [saleDays, setSaleDays] = useState('0');
  const [purchaseDays, setPurchaseDays] = useState('0');
  const [item, setItem] = useState<Item>();
  const [rate, setRate] = useState<Rate>();
  const [channel, setChannel] = useState('sale');
  const [price, setPrice] = useState('');
  const [enabled, setEnabled] = useState(true);
  const policy = useSave();
  const pricing = useSave();
  const [cached, setCached] = useState<Party>();
  const policyVersion = useRef<number | undefined>(undefined);
  const baseline = useRef('');
  useEffect(() => {
    const row = result.data?.data;
    if (!row || row.id !== id) return;
    setCached(row);
    if (!baseline.current) setChannel(row.is_customer ? 'sale' : 'purchase');
    if (!baseline.current || JSON.stringify([limit, saleDays, purchaseDays]) === baseline.current) {
      const values = [
        row.credit_limit_paisa == null ? '' : format(row.credit_limit_paisa),
        String(row.sales_terms_days || 0),
        String(row.purchase_terms_days || 0),
      ];
      baseline.current = JSON.stringify(values);
      policyVersion.current = row.trading_version;
      setLimit(values[0]);
      setSaleDays(values[1]);
      setPurchaseDays(values[2]);
    }
  }, [result.data, id, limit, saleDays, purchaseDays]);
  const party =
    result.data?.data.id === id ? result.data.data : cached?.id === id ? cached : undefined;
  if (!party) return <Loading error={result.error} retry={result.reload} />;
  const unavailable = result.loading || !!result.error;
  const policyDirty = JSON.stringify([limit, saleDays, purchaseDays]) !== baseline.current;
  return (
    <>
      <Heading title={party.name + ' · ' + t('Payment terms / prices')}>
        <Link to={path + '/contacts/' + id}>{t('Party')}</Link>
        <Link to={`${path}/followups?contact=${id}&new=1`}>{t('Add follow-up')}</Link>
      </Heading>
      <Errors error={result.error} />
      <PartyListAssignments key={id} party={party} disabled={unavailable} />
      <form
        className="panel narrow-form"
        data-dirty={policyDirty ? 'true' : 'false'}
        onSubmit={(e) => {
          e.preventDefault();
          if (unavailable || policy.busy || policy.uncertain) return;
          void policy.save<Party>(
            `${base}/contacts/${id}/trading`,
            {
              version: policyVersion.current,
              credit_limit: limit || null,
              sales_terms_days: Number(saleDays),
              purchase_terms_days: Number(purchaseDays),
            },
            (row) => {
              baseline.current = JSON.stringify([limit, saleDays, purchaseDays]);
              policyVersion.current = row.trading_version;
              changed();
            },
            'PATCH',
          );
        }}
      >
        <h2>{t('Payment terms / credit')}</h2>
        <Errors error={policy.error} />
        <Retry form={policy} />
        <fieldset
          className="import-fields"
          disabled={policy.busy || policy.uncertain || unavailable || !!party.archived_at}
        >
          <Field
            label={t('Customer credit limit (NPR)')}
            inputMode="decimal"
            value={limit}
            onChange={(e) => setLimit(e.target.value)}
          />
          <p className="subtle">
            {t(
              'Blank means unlimited. Zero means fully paid sales. Limit includes existing customer balance; supplier dues stay separate.',
            )}
          </p>
          <Field
            label={t('Customer payment days')}
            type="number"
            min={0}
            max={3650}
            required
            value={saleDays}
            onChange={(e) => setSaleDays(e.target.value)}
          />
          <Field
            label={t('Supplier payment days')}
            type="number"
            min={0}
            max={3650}
            required
            value={purchaseDays}
            onChange={(e) => setPurchaseDays(e.target.value)}
          />
          <small>
            {t('Calendar days use BS dates. Explicit bill due dates override defaults.')}
          </small>
        </fieldset>
        <Submit
          busy={policy.busy}
          disabled={policy.uncertain || unavailable || !!party.archived_at}
        >
          {t('Save payment terms')}
        </Submit>
        <IonButton
          fill="clear"
          disabled={policy.busy || policy.uncertain || unavailable}
          onClick={() => {
            if (policyDirty && !window.confirm(t('Discard unsaved payment terms?'))) return;
            const values = [
              party.credit_limit_paisa == null ? '' : format(party.credit_limit_paisa),
              String(party.sales_terms_days || 0),
              String(party.purchase_terms_days || 0),
            ];
            baseline.current = JSON.stringify(values);
            policyVersion.current = party.trading_version;
            setLimit(values[0]);
            setSaleDays(values[1]);
            setPurchaseDays(values[2]);
            policy.clear();
          }}
        >
          {t('Reload payment terms')}
        </IonButton>
      </form>
      <form
        className="panel narrow-form"
        onSubmit={(e) => {
          e.preventDefault();
          void pricing.save(
            `${base}/contacts/${id}/rates`,
            {
              item_id: rate?.item_id || item?.id,
              channel: rate?.channel || channel,
              price,
              enabled,
              ...(rate ? { version: rate.version } : {}),
            },
            () => {
              setRate(undefined);
              setItem(undefined);
              setPrice('');
              changed();
            },
          );
        }}
      >
        <h2>{t('Agreed item prices')}</h2>
        <p>
          {t(
            'New entries suggest these prices. Approved quotes and saved bills keep their original prices.',
          )}
        </p>
        <Errors error={pricing.error} />
        <Retry form={pricing} />
        {rate ? (
          <strong>
            {rate.item_name} · {rate.unit_snapshot}
          </strong>
        ) : (
          <Picker kind="items" onPick={(row) => setItem(row as Item)} />
        )}
        <p>{item?.name}</p>
        <>
          {rate ? (
            t(rate.channel === 'sale' ? 'Sales' : 'Purchases')
          ) : (
            <Select label={t('Price for')} value={channel} onChange={setChannel}>
              {party.is_customer && <option value="sale">{t('Sales')}</option>}
              {party.is_supplier && <option value="purchase">{t('Purchases')}</option>}
            </Select>
          )}
        </>
        <Field
          label={t('Agreed price (NPR)')}
          value={price}
          inputMode="decimal"
          required
          onChange={(e) => setPrice(e.target.value)}
        />
        <Check checked={enabled} onChange={setEnabled}>
          {t('Use this price')}
        </Check>
        <Submit
          busy={pricing.busy}
          disabled={pricing.uncertain || (!rate && !item) || !!party.archived_at}
        >
          {t('Save price')}
        </Submit>
        {rate && (
          <IonButton
            fill="clear"
            onClick={() => {
              setRate(undefined);
              setPrice('');
            }}
          >
            {t('Cancel edit')}
          </IonButton>
        )}
      </form>
      {rates.loading || rates.error ? (
        <Loading error={rates.error} retry={rates.reload} />
      ) : (
        <section className="panel narrow-form">
          {rates.data?.data.map((row) => (
            <div className="mini-row" key={row.id}>
              <span>
                {row.item_name}
                <small>
                  {row.channel} · {row.unit_snapshot} · {row.enabled ? t('Enabled') : t('Disabled')}
                </small>
              </span>
              <strong>{currency(row.price_paisa)}</strong>
              <IonButton
                fill="clear"
                disabled={pricing.busy || pricing.uncertain}
                onClick={() => {
                  setRate(row);
                  setChannel(row.channel);
                  setPrice(format(row.price_paisa));
                  setEnabled(row.enabled);
                }}
              >
                {t('Edit')}
              </IonButton>
            </div>
          ))}
          <Pagination page={page} pages={rates.data?.last_page || 1} change={setPage} />
        </section>
      )}
    </>
  );
}
