import { IonButton } from '@ionic/react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { useEffect, useState } from 'react';
import { useWorkspace } from '../lib/context';
import { ApiError, useData, useSave } from '../lib/api.screen-fit-daily';
import { amount, bsDisplay, currency, format, quantity, round } from '../lib/money';
import type { Doc, Item, Lookup, Page, Party } from '../lib/types';
import Picker from '../components/Picker';
import EntrySteps from '../components/EntrySteps';
import {
  Check,
  Errors,
  Field,
  Heading,
  Loading,
  OptionalDetails,
  Select,
  Submit,
} from '../components/ui';
import { Pagination } from './Records';

export function MoneyForm() {
  const { kind = 'receipt' } = useParams();
  const { base, path, today, business, changed, t } = useWorkspace();
  const search = new URLSearchParams(useLocation().search);
  const contactId = search.get('contact');
  const documentId = search.get('document');
  const navigate = useNavigate();
  const lookup = useData<{ data: Lookup }>(`${base}/lookup`);
  const contact = useData<{ data: Party }>(contactId ? `${base}/contacts/${contactId}` : null);
  const [party, setParty] = useState<Party>();
  const [value, setValue] = useState(search.get('amount') || '0');
  const [date, setDate] = useState(bsDisplay(today));
  const [account, setAccount] = useState('');
  const [destination, setDestination] = useState('');
  const [notes, setNotes] = useState('');
  const [advance, setAdvance] = useState(false);
  const [overdraft, setOverdraft] = useState(false);
  const [manual, setManual] = useState(false);
  const [allocations, setAllocations] = useState<Record<string, string>>({});
  const form = useSave();
  const locked = form.busy || form.uncertain;
  const owner = ['contribution', 'withdrawal'].includes(kind);
  const transfer = kind === 'transfer';
  const customer = ['receipt', 'customer_refund'].includes(kind);
  const titles: Record<string, string> = {
    receipt: 'Receive money',
    supplier_payment: 'Pay party',
    customer_refund: 'Customer refund',
    supplier_refund: 'Payee refund',
    transfer: 'Move money',
    contribution: 'Add my money',
    withdrawal: 'Personal withdrawal',
  };
  const accounts = lookup.data?.data.accounts || [];
  const selected = account || accounts[0]?.id || '';
  let validAmount = false;
  try {
    validAmount = amount(value) > 0n;
  } catch {
    /* Wait for complete input. */
  }
  useEffect(() => {
    if (contact.data) setParty(contact.data.data);
  }, [contact.data]);
  const preview = useData<{
    data: {
      bills: { id: string; number: string; available_paisa: string; suggested_paisa: string }[];
      unallocated_paisa: string;
      party_balance_paisa?: string;
    };
  }>(
    !owner && !transfer && party && validAmount
      ? `${base}/payments/preview?kind=${kind}&contact_id=${party.id}&amount=${encodeURIComponent(value)}`
      : null,
  );
  function chooseParty(row?: Party) {
    setParty(row);
    setManual(false);
    setAllocations({});
    setAdvance(false);
  }
  const accountField = (
    <Select
      label={t(
        transfer
          ? 'From'
          : ['receipt', 'supplier_refund', 'contribution'].includes(kind)
            ? 'Received into'
            : 'Paid from',
      )}
      name="money_account_id"
      value={selected}
      onChange={setAccount}
    >
      {accounts.map((a) => (
        <option value={a.id} key={a.id}>
          {a.name}
        </option>
      ))}
    </Select>
  );
  if (!titles[kind] || (business.role === 'cashier' && kind !== 'receipt'))
    return <p>{t('Action unavailable for this role.')}</p>;
  return (
    <div className="daily-entry">
      <Heading title={t(titles[kind])}>
        <Link to={path + '/money'}>{t('Back')}</Link>
      </Heading>
      <EntrySteps
        labels={[owner || transfer ? 'Accounts' : 'Party', 'Amount', 'Review'].map(t)}
        dirty={validAmount || !!party}
        busy={locked}
        error={form.error}
        t={t}
        canContinue={[
          owner
            ? !!selected
            : transfer
              ? !!selected &&
                !!(destination || accounts[1]?.id) &&
                selected !== (destination || accounts[1]?.id)
              : !!party,
          validAmount && !!selected,
        ]}
        onSubmit={(e) => {
          e.preventDefault();
          if (locked) return;
          const input: Record<string, unknown> = {
            kind,
            amount: value,
            business_date_bs: date,
            money_account_id: selected,
            notes,
            overdraft_confirmed: overdraft,
          };
          if (transfer) input.destination_account_id = destination || accounts[1]?.id;
          else if (!owner) {
            input.contact_id = party?.id;
            input.unallocated_confirmed = advance;
            if (documentId) input.allocations = [{ document_id: documentId, amount: value }];
            else if (manual)
              input.allocations = Object.entries(allocations)
                .filter(([, value]) => {
                  try {
                    return amount(value) > 0n;
                  } catch {
                    return true;
                  }
                })
                .map(([document_id, amount]) => ({ document_id, amount }));
          }
          void form.save(`${base}/${owner ? 'owner-money' : 'payments'}`, input, () => {
            changed();
            navigate(documentId ? `${path}/document/${documentId}` : path + '/money');
          });
        }}
      >
        <Errors error={form.error || lookup.error || preview.error} />
        {form.uncertain && (
          <IonButton disabled={form.busy} onClick={() => void form.retry()}>
            {t('Retry original action')}
          </IonButton>
        )}
        <fieldset className="entry-lock" disabled={locked}>
          <div className="form-main">
            <section className="panel" data-entry-step="0">
              <h2>{t(owner || transfer ? 'Accounts' : 'Party')}</h2>
              {owner || transfer ? (
                <>
                  {accountField}
                  {transfer && (
                    <Select
                      label={t('To')}
                      name="destination_account_id"
                      value={destination || accounts[1]?.id || ''}
                      onChange={setDestination}
                    >
                      {accounts
                        .filter((a) => a.id !== selected)
                        .map((a) => (
                          <option value={a.id} key={a.id}>
                            {a.name}
                          </option>
                        ))}
                    </Select>
                  )}
                  <p className="subtle">
                    {t(
                      owner
                        ? 'Your own money stays separate from sales and expenses.'
                        : 'Move between your accounts. Total business money stays the same.',
                    )}
                  </p>
                </>
              ) : party ? (
                <div className="selected-party">
                  <strong>{party.name}</strong>
                  <IonButton fill="clear" onClick={() => chooseParty()}>
                    {t('Change')}
                  </IonButton>
                </div>
              ) : (
                <Picker
                  kind="contacts"
                  customer={customer}
                  payable={!customer}
                  onPick={(row) => chooseParty(row as Party)}
                />
              )}
            </section>
            <section className="panel" data-entry-step="1">
              <h2>{t('Amount')}</h2>
              <Field
                label={t('Amount') + ' (NPR)'}
                name="amount"
                inputMode="decimal"
                value={value}
                onChange={(e) => setValue(e.target.value)}
                required
              />
              {!owner && !transfer && accountField}
            </section>
            <section className="panel" data-entry-step="2">
              <h2>{t('Review')}</h2>
              <p className="daily-review">
                {party?.name || accounts.find((a) => a.id === selected)?.name}
                {(transfer || party) &&
                  ` ${transfer ? '→' : '·'} ${accounts.find((a) => a.id === (transfer ? destination || accounts[1]?.id : selected))?.name || ''}`}{' '}
                · {validAmount ? currency(amount(value)) : value + ' NPR'}
              </p>
              <Field
                label={t('Business date (BS)')}
                name="business_date_bs"
                value={date}
                onChange={(e) => setDate(e.target.value)}
                required
              />
              {preview.data && (
                <div className="allocation-preview">
                  <strong>
                    {t(documentId ? 'Applied to selected bill' : 'Bills suggested')}{' '}
                    {!documentId &&
                      preview.data.data.bills.filter((b) => BigInt(b.suggested_paisa) > 0n).length}
                  </strong>
                  {BigInt(preview.data.data.unallocated_paisa) > 0n && (
                    <p>
                      {t('Unapplied amount')}:{' '}
                      <strong>{currency(preview.data.data.unallocated_paisa)}</strong>
                    </p>
                  )}
                </div>
              )}
              <OptionalDetails label={t('Additional details')}>
                {!documentId && preview.data && (
                  <details
                    onToggle={(e) => {
                      if ((e.target as HTMLDetailsElement).open && !manual && !locked) {
                        setAllocations(
                          Object.fromEntries(
                            preview.data!.data.bills.map((b) => [b.id, format(b.suggested_paisa)]),
                          ),
                        );
                        setManual(true);
                      }
                    }}
                  >
                    <summary>{t('Choose bills')}</summary>
                    {preview.data.data.bills.map((b) => (
                      <Field
                        key={b.id}
                        label={`${b.number} · ${t('Available')} ${currency(b.available_paisa)}`}
                        inputMode="decimal"
                        value={allocations[b.id] || '0'}
                        onChange={(e) =>
                          setAllocations((previous) => ({ ...previous, [b.id]: e.target.value }))
                        }
                      />
                    ))}
                  </details>
                )}
                {!owner && !transfer && business.role !== 'cashier' && (
                  <Check checked={advance} onChange={setAdvance}>
                    {t('Explicitly keep unapplied amount as advance / starting-balance settlement')}
                  </Check>
                )}
                <Field
                  label={t('Notes')}
                  name="notes"
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                />
                {['owner', 'accountant'].includes(business.role) &&
                  !['receipt', 'supplier_refund', 'contribution'].includes(kind) && (
                    <details>
                      <summary>{t('Bank overdraft')}</summary>
                      <Check checked={overdraft} onChange={setOverdraft}>
                        {t('Allow confirmed bank overdraft')}
                      </Check>
                    </details>
                  )}
              </OptionalDetails>
              <div className="daily-submit">
                <Submit
                  busy={form.busy}
                  disabled={locked || !validAmount || !selected || (!owner && !transfer && !party)}
                >
                  {t('Save')}
                </Submit>
              </div>
            </section>
          </div>
        </fieldset>
      </EntrySteps>
    </div>
  );
}

export function ReturnForm() {
  const { id = '' } = useParams();
  const { base, path, today, changed, t } = useWorkspace();
  const source = useData<{ data: Doc }>(`${base}/document/${id}`);
  const lookup = useData<{ data: Lookup }>(`${base}/lookup`);
  const [quantities, setQuantities] = useState<Record<string, string>>({});
  const [page, setPage] = useState(1);
  const [reason, setReason] = useState('');
  const [date, setDate] = useState(bsDisplay(today));
  const [refund, setRefund] = useState(false);
  const [account, setAccount] = useState('');
  const form = useSave();
  const locked = form.busy || form.uncertain;
  const navigate = useNavigate();
  const doc = source.data?.data;
  useEffect(() => {
    if (!(form.error instanceof ApiError) || !doc) return;
    const match = Object.keys(form.error.errors)[0]?.match(/^lines\.(\d+)\./);
    if (!match) return;
    const selected = doc.lines.filter((line) => {
      try {
        return quantity(quantities[line.id] || '0') > 0n;
      } catch {
        return true;
      }
    })[Number(match[1])];
    if (selected)
      setPage(Math.floor(doc.lines.findIndex((line) => line.id === selected.id) / 2) + 1);
  }, [form.error, doc, quantities]);
  if (source.loading || source.error || !doc)
    return <Loading error={source.error} retry={source.reload} />;
  let total = 0n;
  let inputError: Error | undefined;
  const returns: { source_line_id: string; qty: string }[] = [];
  try {
    doc.lines.forEach((line) => {
      const text = quantities[line.id] || '0';
      const q = quantity(text);
      if (!q) return;
      const originalQty = BigInt(line.qty_milli);
      const already = originalQty - BigInt(line.returnable_qty_milli);
      if (q > BigInt(line.returnable_qty_milli))
        throw new Error(t('Quantity exceeds available return.'));
      total +=
        round(BigInt(line.net_base_paisa), already + q, originalQty) -
        round(BigInt(line.net_base_paisa), already, originalQty) +
        round(BigInt(line.tax_paisa), already + q, originalQty) -
        round(BigInt(line.tax_paisa), already, originalQty);
      returns.push({ source_line_id: line.id, qty: text });
    });
  } catch (error) {
    inputError = error as Error;
  }
  const pages = Math.max(1, Math.ceil(doc.lines.length / 2));
  const currentPage = Math.min(page, pages);
  return (
    <div className="daily-entry">
      <Heading title={t('Return goods')}>
        <Link to={`${path}/document/${id}`}>{t('Back')}</Link>
      </Heading>
      <EntrySteps
        labels={['Items', 'Reason', 'Review'].map(t)}
        dirty={!!returns.length}
        busy={locked}
        error={form.error}
        t={t}
        canContinue={[!!returns.length && !inputError, reason.trim().length >= 5]}
        onSubmit={(e) => {
          e.preventDefault();
          if (locked) return;
          void form.save<Doc>(
            `${base}/document/${id}/returns`,
            {
              lines: returns,
              business_date_bs: date,
              reason,
              refund_now: refund,
              money_account_id: account || lookup.data?.data.accounts[0]?.id,
            },
            (returned) => {
              changed();
              navigate(`${path}/document/${returned.id}`);
            },
          );
        }}
      >
        <Errors error={form.error || inputError || lookup.error} />
        {form.uncertain && (
          <IonButton disabled={form.busy} onClick={() => void form.retry()}>
            {t('Retry original action')}
          </IonButton>
        )}
        <fieldset className="entry-lock" disabled={locked}>
          <div className="form-main">
            <section className="panel" data-entry-step="0">
              <h2>{t('Items')}</h2>
              <p className="daily-review">
                {doc.number} · {t('Original prices and tax')}
              </p>
              {doc.lines.map((line, index) => (
                <div
                  className="return-row"
                  key={line.id}
                  hidden={Math.floor(index / 2) + 1 !== currentPage}
                >
                  <div>
                    <strong>{line.description}</strong>
                    <small>
                      {format(line.returnable_qty_milli, 3)} {line.unit_snapshot} {t('Available')}
                    </small>
                  </div>
                  <Field
                    label={`${t('Quantity')} · ${line.description}`}
                    name={
                      returns.some((row) => row.source_line_id === line.id)
                        ? `lines.${returns.findIndex((row) => row.source_line_id === line.id)}.qty`
                        : undefined
                    }
                    inputMode="decimal"
                    value={quantities[line.id] || '0'}
                    onChange={(e) =>
                      setQuantities((previous) => ({ ...previous, [line.id]: e.target.value }))
                    }
                  />
                </div>
              ))}
              {pages > 1 && (
                <nav className="home-pager" aria-label={t('Return items')}>
                  <button
                    type="button"
                    disabled={locked || currentPage === 1}
                    onClick={() => setPage(currentPage - 1)}
                  >
                    {t('Previous items')}
                  </button>
                  <span>
                    {currentPage} / {pages}
                  </span>
                  <button
                    type="button"
                    disabled={locked || currentPage === pages}
                    onClick={() => setPage(currentPage + 1)}
                  >
                    {t('Next items')}
                  </button>
                </nav>
              )}
            </section>
            <section className="panel" data-entry-step="1">
              <h2>{t('Reason')}</h2>
              <Field
                label={t('Reason')}
                name="reason"
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                minLength={5}
                required
              />
              <Field
                label={t('Return date (BS)')}
                name="business_date_bs"
                value={date}
                onChange={(e) => setDate(e.target.value)}
                required
              />
            </section>
            <section className="panel" data-entry-step="2">
              <h2>{t('Review')}</h2>
              <p className="daily-review">
                {doc.number} · {returns.length} {t('Items')} · {date} BS
              </p>
              <div className="grand-total">
                <span>{t('Bill reduction')}</span>
                <strong>{currency(total)}</strong>
              </div>
              <Check checked={refund} onChange={setRefund}>
                {t(
                  doc.type === 'sale' ? 'Refund customer now' : 'Refund received from supplier now',
                )}
              </Check>
              {refund && (
                <>
                  <p className="subtle">
                    {t('Only refundable credit moves money. Unpaid portion simply reduces bill.')}
                  </p>
                  <Select
                    label={t('Payment account')}
                    name="money_account_id"
                    value={account || lookup.data?.data.accounts[0]?.id || ''}
                    onChange={setAccount}
                  >
                    {lookup.data?.data.accounts.map((a) => (
                      <option key={a.id} value={a.id}>
                        {a.name}
                      </option>
                    ))}
                  </Select>
                </>
              )}
              <div className="daily-submit">
                <Submit busy={form.busy} disabled={locked || !returns.length || !!inputError}>
                  {t('Create return')}
                </Submit>
              </div>
            </section>
          </div>
        </fieldset>
      </EntrySteps>
    </div>
  );
}

export function CountForm() {
  const { base, path, today, business, revision, changed, t } = useWorkspace();
  const query = new URLSearchParams(useLocation().search);
  const [item, setItem] = useState<Item>();
  const selected = useData<{ data: Item }>(
    query.get('item') ? `${base}/items/${query.get('item')}` : null,
    revision,
  );
  const [page, setPage] = useState(1);
  const [showHistory, setShowHistory] = useState(false);
  const [entry, setEntry] = useState(0);
  const history = useData<
    Page<{
      id: string;
      item_id: string;
      qty_delta_milli: string;
      business_date_bs: number;
      reason: string;
      status: string;
    }>
  >(`${base}/stock-adjustments?page=${page}`, revision);
  const [count, setCount] = useState('0');
  const [cost, setCost] = useState('0');
  const [reason, setReason] = useState('');
  const [date, setDate] = useState(bsDisplay(today));
  const [zero, setZero] = useState(false);
  const form = useSave();
  const cancel = useSave();
  const locked = form.busy || form.uncertain;
  useEffect(() => {
    if (selected.data?.data) {
      setItem(selected.data.data);
      setCount(format(selected.data.data.qty_milli, 3));
    }
  }, [selected.data]);
  let validCount = false;
  try {
    quantity(count);
    amount(cost);
    validCount = true;
  } catch {
    /* Wait for complete input. */
  }
  if (!['owner', 'manager'].includes(business.role))
    return <p>{t('Stock counts require owner or manager.')}</p>;
  return (
    <div className="daily-entry">
      <Heading title={t(showHistory ? 'Count history' : 'Count stock')}>
        <IonButton
          fill="clear"
          disabled={locked || cancel.busy || cancel.uncertain}
          onClick={() => setShowHistory(!showHistory)}
        >
          {t(showHistory ? 'Count stock' : 'Count history')}
        </IonButton>
        <Link to={path + '/items'}>{t('Back')}</Link>
      </Heading>
      {showHistory ? (
        <section className="panel">
          <Errors error={cancel.error} />
          {cancel.uncertain && (
            <IonButton disabled={cancel.busy} onClick={() => void cancel.retry()}>
              {t('Retry original action')}
            </IonButton>
          )}
          {(history.loading || history.error) && (
            <Loading error={history.error} retry={history.reload} />
          )}
          {history.data?.data.map((row) => (
            <div className="mini-row" key={row.id}>
              <div>
                <strong>{row.reason}</strong>
                <small>
                  {bsDisplay(row.business_date_bs)} · {t('Change')} {format(row.qty_delta_milli, 3)}{' '}
                  · {row.status}
                </small>
              </div>
              {row.status === 'posted' && (
                <IonButton
                  disabled={cancel.busy || cancel.uncertain}
                  color="danger"
                  fill="clear"
                  onClick={() => {
                    const reason = window.prompt(
                      t('Reason for cancelling stock count (minimum 5 characters)'),
                    );
                    if (reason)
                      void cancel.save(
                        `${base}/stock-adjustments/${row.id}/cancel`,
                        { reason, business_date_bs: today },
                        () => changed(),
                      );
                  }}
                >
                  {t('Cancel')}
                </IonButton>
              )}
            </div>
          ))}
          {history.data && (
            <Pagination page={page} pages={history.data.last_page} change={setPage} />
          )}
        </section>
      ) : (
        <EntrySteps
          key={entry}
          labels={['Item', 'Count', 'Review'].map(t)}
          dirty={!!item}
          busy={locked}
          error={form.error}
          t={t}
          canContinue={[!!item, validCount]}
          onSubmit={(e) => {
            e.preventDefault();
            if (locked) return;
            void form.save(
              `${base}/stock-adjustments`,
              {
                item_id: item?.id,
                counted_qty: count,
                expected_qty_milli: item?.qty_milli,
                unit_cost: cost,
                business_date_bs: date,
                reason,
                zero_cost_confirmed: zero,
              },
              () => {
                changed();
                setItem(undefined);
                setCount('0');
                setCost('0');
                setReason('');
                setZero(false);
                setEntry((value) => value + 1);
              },
            );
          }}
        >
          <Errors error={form.error} />
          {form.uncertain && (
            <IonButton disabled={form.busy} onClick={() => void form.retry()}>
              {t('Retry original action')}
            </IonButton>
          )}
          <fieldset className="entry-lock" disabled={locked}>
            <div className="form-main">
              <section className="panel" data-entry-step="0">
                <h2>{t('Item')}</h2>
                {item ? (
                  <div className="selected-party">
                    <div>
                      <strong>{item.name}</strong>
                      <small>
                        {t('Recorded stock')} {format(item.qty_milli, 3)} {item.unit_label}
                      </small>
                    </div>
                    <IonButton fill="clear" onClick={() => setItem(undefined)}>
                      {t('Change')}
                    </IonButton>
                  </div>
                ) : (
                  <Picker
                    kind="items"
                    stockOnly
                    onPick={(row) => {
                      setItem(row as Item);
                      setCount(format((row as Item).qty_milli, 3));
                      setZero(false);
                    }}
                  />
                )}
              </section>
              <section className="panel" data-entry-step="1">
                <h2>{t('Count')}</h2>
                <Field
                  label={t('Physically counted quantity')}
                  name="counted_qty"
                  inputMode="decimal"
                  value={count}
                  onChange={(e) => setCount(e.target.value)}
                  required
                />
                <Field
                  label={t('Cost per extra unit (NPR)')}
                  name="unit_cost"
                  inputMode="decimal"
                  value={cost}
                  onChange={(e) => setCost(e.target.value)}
                />
                <OptionalDetails label={t('Zero-cost extra stock')}>
                  <Check checked={zero} onChange={setZero}>
                    {t('Confirm extra stock has zero cost')}
                  </Check>
                </OptionalDetails>
              </section>
              <section className="panel" data-entry-step="2">
                <h2>{t('Review')}</h2>
                <p className="daily-review">
                  {item?.name} · {t('Recorded stock')} {item && format(item.qty_milli, 3)} → {count}{' '}
                  {item?.unit_label}
                </p>
                <p className="daily-review">
                  {t('Extra unit cost')}: {cost} NPR{zero && ` · ${t('Zero cost confirmed')}`}
                </p>
                <Field
                  label={t('Business date (BS)')}
                  name="business_date_bs"
                  value={date}
                  onChange={(e) => setDate(e.target.value)}
                  required
                />
                <Field
                  label={t('Reason')}
                  name="reason"
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  minLength={5}
                  required
                />
                <div className="daily-submit">
                  <Submit busy={form.busy} disabled={locked || !item || !validCount}>
                    {t('Save count')}
                  </Submit>
                </div>
              </section>
            </div>
          </fieldset>
        </EntrySteps>
      )}
    </div>
  );
}
