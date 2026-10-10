import { IonButton } from '@ionic/react';
import type { QuantityPrice } from '../lib/pricing';
import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useWorkspace } from '../lib/context';
import { request, useData, useOnline, useSave } from '../lib/api';
import { amount, bsDisplay, currency, format, quantity } from '../lib/money';
import type { Item, Page, Party, PriceList, PriceListRule } from '../lib/types';
import Picker from '../components/Picker';
import EntrySteps from '../components/EntrySteps';
import {
  Check,
  Empty,
  Errors,
  Field,
  Heading,
  Loading,
  OptionalDetails,
  Select,
  Submit,
} from '../components/ui';
import { Pagination } from './Records';

export function PriceListSelect({
  channel,
  value,
  onChange,
  disabled = false,
  label,
}: {
  channel: 'sale' | 'purchase';
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
  label?: string;
}) {
  const { base, revision, t } = useWorkspace();
  const [page, setPage] = useState(1);
  const lists = useData<Page<PriceList>>(
    `${base}/price-lists?channel=${channel}&enabled=1&page=${page}`,
    revision,
  );
  const missing = !!value && !lists.data?.data.some((row) => row.id === value);
  const selected = useData<{ data: PriceList }>(
    missing ? `${base}/price-lists/${value}` : null,
    revision,
  );
  return (
    <fieldset className="import-fields" disabled={disabled}>
      <Select label={label || t('Price list (optional)')} value={value} onChange={onChange}>
        <option value="">{t('Party / standard prices')}</option>
        {missing && (
          <option value={value}>{selected.data?.data.name || t('Saved price list')}</option>
        )}
        {lists.data?.data.map((row) => (
          <option key={row.id} value={row.id}>
            {row.name}
          </option>
        ))}
      </Select>
      <Errors error={lists.error || selected.error} />
      {lists.loading && <small role="status">{t('Loading price lists…')}</small>}
      {(lists.data?.last_page || 1) > 1 && (
        <Pagination page={page} pages={lists.data!.last_page} change={setPage} />
      )}
    </fieldset>
  );
}

export function PriceLists() {
  const { base, path, revision, business, t } = useWorkspace();
  const [channel, setChannel] = useState<'sale' | 'purchase'>('sale');
  const [page, setPage] = useState(1);
  const manage = business.role !== 'cashier';
  const lists = useData<Page<PriceList>>(
    `${base}/price-lists?channel=${channel}&page=${page}`,
    revision,
  );
  return (
    <>
      <Heading
        title={t('Price lists')}
        description={t(
          'Shared prices for customers or suppliers. Quantity tiers use each item’s base unit.',
        )}
      >
        {manage && <Link to={path + '/price-lists/new'}>{t('New price list')}</Link>}
        {manage && <Link to={path + '/imports?resource=price_lists'}>{t('Import CSV')}</Link>}
      </Heading>
      <Select
        label={t('Price for')}
        value={channel}
        onChange={(value) => {
          setChannel(value as 'sale' | 'purchase');
          setPage(1);
        }}
      >
        <option value="sale">{t('Sales')}</option>
        {manage && <option value="purchase">{t('Purchases')}</option>}
      </Select>
      {lists.loading || lists.error ? (
        <Loading error={lists.error} retry={lists.reload} />
      ) : lists.data?.data.length ? (
        <section className="panel">
          {lists.data.data.map((row) => (
            <Link className="mini-row" key={row.id} to={path + '/price-lists/' + row.id}>
              <span>
                <strong>{row.name}</strong>
                <small>
                  {t(row.channel === 'sale' ? 'Sales' : 'Purchases')} ·{' '}
                  {t(row.enabled ? 'Enabled' : 'Disabled')}
                </small>
              </span>
              <span>
                {format(row.adjustment_bps)}%
                {row.starts_bs && (
                  <small>
                    {bsDisplay(row.starts_bs)} BS →{' '}
                    {row.ends_bs ? bsDisplay(row.ends_bs) : t('No end date')}
                  </small>
                )}
              </span>
            </Link>
          ))}
          <Pagination page={page} pages={lists.data.last_page} change={setPage} />
        </section>
      ) : (
        <Empty title={t('No price lists yet')} />
      )}
    </>
  );
}

export function ReviewedPrices({
  lines,
  channel,
  date,
  listId = '',
  contactId,
  busy = false,
  onApply,
}: {
  lines: { item_id?: string; qty: string; name?: string; unit?: string }[];
  channel: 'sale' | 'purchase';
  date: string;
  listId?: string;
  contactId?: string;
  busy?: boolean;
  onApply: (prices: QuantityPrice[]) => void;
}) {
  const { base, revision, t } = useWorkspace();
  const online = useOnline();
  const input = JSON.stringify({
    lines: lines.filter((row) => row.item_id),
    channel,
    date,
    listId,
    contactId,
    revision,
  });
  const [proof, setProof] = useState<{ input: string; prices: QuantityPrice[] }>();
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<Error>();
  const controller = useRef<AbortController | undefined>(undefined);
  useEffect(() => {
    controller.current?.abort();
    controller.current = undefined;
    setLoading(false);
    setError(undefined);
    return () => controller.current?.abort();
  }, [base, input]);
  async function review() {
    if (busy || loading || controller.current || !online) return;
    setError(undefined);
    const query = new URLSearchParams({ channel, business_date_bs: date });
    try {
      const totals = new Map<string, bigint>();
      for (const row of lines) {
        if (!row.item_id) continue;
        const qty = quantity(row.qty);
        if (qty <= 0n) throw new Error(t('Quantity must be positive.'));
        totals.set(row.item_id, (totals.get(row.item_id) || 0n) + qty);
      }
      for (const [id, qty] of totals) {
        query.append('items[]', id);
        query.set(`quantities[${id}]`, format(qty, 3));
      }
    } catch (cause) {
      setError(cause as Error);
      return;
    }
    if (listId) query.set('price_list_id', listId);
    const abort = new AbortController();
    controller.current = abort;
    setLoading(true);
    try {
      const result = await request<{ data: QuantityPrice[] }>(
        `${base}/${contactId ? 'contacts/' + contactId + '/' : ''}price-suggestions?${query}`,
        { signal: abort.signal },
      );
      if (!abort.signal.aborted) setProof({ input, prices: result.data });
    } catch (cause) {
      if (!abort.signal.aborted) {
        setProof(undefined);
        setError(cause as Error);
      }
    } finally {
      if (!abort.signal.aborted) {
        controller.current = undefined;
        setLoading(false);
      }
    }
  }
  const ready = proof?.input === input;
  return (
    <div className="rate-suggestion">
      <IonButton
        fill="outline"
        disabled={busy || loading || !online || !lines.length}
        onClick={() => void review()}
      >
        {t('Review quantity prices')}
      </IonButton>
      <IonButton
        fill="clear"
        disabled={busy || loading || !online || !ready}
        onClick={() => {
          if (ready) {
            try {
              onApply(proof!.prices);
              setError(undefined);
            } catch (cause) {
              setError(cause as Error);
            }
          }
        }}
      >
        {t('Apply reviewed prices')}
      </IonButton>
      <small>
        {t(
          'Changing party, list, day or quantity needs a new review. Entered prices stay until applied.',
        )}
      </small>
      <Errors error={error} />
      {ready && (
        <small role="status">
          {proof!.prices
            .map((row) => {
              const item = lines.find((line) => line.item_id === row.item_id);
              return row.pricing_scheme === 'slab'
                ? (item?.name ? item.name + ': ' : '') +
                    t('Slab') +
                    ' · ' +
                    row.segments
                      ?.map(
                        (segment) =>
                          format(segment.qty_milli, 3) +
                          (item?.unit ? ' ' + item.unit : '') +
                          ' × ' +
                          currency(segment.price_paisa),
                      )
                      .join(' + ') +
                    ' = ' +
                    currency(row.gross_paisa || '0')
                : (item?.name ? item.name + ': ' : '') +
                    currency(row.price_paisa) +
                    (item?.unit ? ' / ' + item.unit : '');
            })
            .join(' · ')}
        </small>
      )}
    </div>
  );
}

interface DraftRule {
  item_id: string;
  item_name: string;
  unit_snapshot: string;
  pos_unit: string;
  item_kind: string;
  min_qty: string;
  price: string;
  current_unit_snapshot?: string;
  current_pos_unit?: string;
  current_item_kind?: string;
  item_archived?: boolean;
}
interface Draft {
  name: string;
  pricing_scheme: 'volume' | 'slab';
  channel: 'sale' | 'purchase';
  enabled: boolean;
  adjustment_mode: 'increase' | 'decrease';
  adjustment_percent: string;
  starts_bs: string;
  ends_bs: string;
  rules: DraftRule[];
}
const blank: Draft = {
  name: '',
  pricing_scheme: 'volume',
  channel: 'sale',
  enabled: true,
  adjustment_mode: 'decrease',
  adjustment_percent: '0',
  starts_bs: '',
  ends_bs: '',
  rules: [],
};
function ruleDraft(row: PriceListRule): DraftRule {
  return { ...row, min_qty: format(row.min_qty_milli, 3), price: format(row.price_paisa) };
}
function draftList(row: PriceList): Draft {
  const bps = BigInt(row.adjustment_bps);
  return {
    name: row.name,
    pricing_scheme: row.pricing_scheme || 'volume',
    channel: row.channel,
    enabled: row.enabled,
    adjustment_mode: bps < 0n ? 'decrease' : 'increase',
    adjustment_percent: format(bps < 0n ? -bps : bps),
    starts_bs: row.starts_bs ? bsDisplay(row.starts_bs) : '',
    ends_bs: row.ends_bs ? bsDisplay(row.ends_bs) : '',
    rules: (row.rules || []).map(ruleDraft),
  };
}
function changedItem(row: DraftRule) {
  return (
    row.item_archived ||
    (row.current_unit_snapshot !== undefined &&
      (row.unit_snapshot !== row.current_unit_snapshot ||
        row.pos_unit !== row.current_pos_unit ||
        row.item_kind !== row.current_item_kind))
  );
}

export function PriceListEditor() {
  const { id } = useParams();
  const { base, path, revision, business, changed, t } = useWorkspace();
  const navigate = useNavigate();
  const result = useData<{ data: PriceList }>(id ? `${base}/price-lists/${id}` : null, revision);
  const [draft, setDraft] = useState<Draft>(blank);
  const [localError, setLocalError] = useState<Error>();
  const form = useSave();
  const hydrated = useRef('');
  const editingVersion = useRef<number | undefined>(undefined);
  const initial = useRef(JSON.stringify(blank));
  const manage = business.role !== 'cashier';
  const locked = form.busy || form.uncertain || !manage;
  useEffect(() => {
    const row = result.data?.data;
    if (row && row.id === id && hydrated.current !== id) {
      const value = draftList(row);
      hydrated.current = id;
      editingVersion.current = row.version;
      initial.current = JSON.stringify(value);
      setDraft(value);
    }
  }, [result.data, id]);
  function update(patch: Partial<Draft>) {
    setDraft((previous) => ({ ...previous, ...patch }));
    setLocalError(undefined);
  }
  function rule(index: number, patch: Partial<DraftRule>) {
    setDraft((previous) => ({
      ...previous,
      rules: previous.rules.map((row, i) => (i === index ? { ...row, ...patch } : row)),
    }));
    setLocalError(undefined);
  }
  function add(item: Item) {
    setDraft((previous) => ({
      ...previous,
      rules: [
        ...previous.rules,
        {
          item_id: item.id,
          item_name: item.name,
          unit_snapshot: item.unit_label,
          pos_unit: item.pos_unit || 'unit',
          item_kind: item.kind,
          min_qty: previous.rules.some((row) => row.item_id === item.id) ? '' : '0',
          price: format(
            previous.channel === 'sale'
              ? item.sale_price_paisa
              : item.last_purchase_price_paisa || item.sale_price_paisa,
          ),
        },
      ],
    }));
  }
  async function save() {
    if (locked) return;
    setLocalError(undefined);
    try {
      const percent = amount(draft.adjustment_percent);
      if (percent > (draft.adjustment_mode === 'decrease' ? 9999n : 100000n))
        throw new Error(t('Percentage exceeds supported limit.'));
      const seen = new Map<string, Set<string>>();
      for (const row of draft.rules) {
        const qty = quantity(row.min_qty),
          price = amount(row.price);
        const tiers = seen.get(row.item_id) || new Set<string>();
        if (
          qty > 1000000000n ||
          price <= 0n ||
          price > 1000000000n ||
          tiers.has(qty.toString()) ||
          tiers.size >= 10 ||
          changedItem(row)
        )
          throw new Error(
            t('Check prices, distinct minimums and current item units. At most 10 tiers per item.'),
          );
        tiers.add(qty.toString());
        seen.set(row.item_id, tiers);
      }
      if (draft.pricing_scheme === 'slab' && [...seen.values()].some((tiers) => !tiers.has('0')))
        throw new Error(t('Each slab item needs a zero minimum.'));
      if (draft.rules.length > 1000) throw new Error(t('At most 1000 price rules per list.'));
    } catch (cause) {
      setLocalError(cause as Error);
      return;
    }
    await form.save<PriceList>(
      id ? `${base}/price-lists/${id}` : base + '/price-lists',
      {
        ...draft,
        version: id ? editingVersion.current : undefined,
        starts_bs: draft.starts_bs || null,
        ends_bs: draft.ends_bs || null,
        rules: draft.rules.map(
          ({ item_id, min_qty, price, unit_snapshot, pos_unit, item_kind }) => ({
            item_id,
            min_qty,
            price,
            unit_snapshot,
            pos_unit,
            item_kind,
          }),
        ),
      },
      (row) => {
        editingVersion.current = row.version;
        initial.current = JSON.stringify(draft);
        changed();
        navigate(path + '/price-lists/' + row.id);
      },
      id ? 'PATCH' : 'POST',
    );
  }
  if (id && (result.loading || result.error || result.data?.data.id !== id))
    return <Loading error={result.error} retry={result.reload} />;
  const badUnits = draft.rules.some(changedItem);
  function rangeLabel(row: DraftRule) {
    if (draft.pricing_scheme !== 'slab')
      return `${row.min_qty || '?'} ${row.unit_snapshot} ${t('or more')}`;
    try {
      const start = quantity(row.min_qty);
      const next = draft.rules
        .filter((rule) => rule.item_id === row.item_id && rule.min_qty)
        .map((rule) => quantity(rule.min_qty))
        .filter((qty) => qty > start)
        .sort((a, b) => (a < b ? -1 : a > b ? 1 : 0))[0];
      return `${t('From')} ${format(start, 3)} ${row.unit_snapshot} · ${next === undefined ? t('No upper limit') : t('up to') + ' ' + format(next, 3) + ' ' + row.unit_snapshot}`;
    } catch {
      return t('Check minimum quantity.');
    }
  }
  return (
    <>
      <Heading
        title={t(id ? 'Edit price list' : 'New price list')}
        description={t('Save shared pricing rules. Books and stock stay unchanged.')}
      >
        <Link to={path + '/price-lists'}>{t('Price lists')}</Link>
      </Heading>
      {!manage && <p>{t('View only. Owner, manager or accountant manages lists.')}</p>}
      <EntrySteps
        labels={[t('List details'), t('Item prices / tiers'), t('Review & save')]}
        t={t}
        dirty={JSON.stringify(draft) !== initial.current}
        busy={form.busy || form.uncertain}
        error={form.error || localError}
        canContinue={[!!draft.name.trim(), !badUnits]}
        onSubmit={(event) => {
          event.preventDefault();
          void save();
        }}
      >
        <section className="panel" data-entry-step="0">
          <h2>{t('List details')}</h2>
          <fieldset className="import-fields" disabled={locked}>
            <Field
              label={t('List name')}
              name="name"
              required
              maxLength={100}
              value={draft.name}
              onChange={(event) => update({ name: event.target.value })}
            />
            {id ? (
              <p>{t(draft.channel === 'sale' ? 'Sales' : 'Purchases')}</p>
            ) : (
              <Select
                label={t('Price for')}
                value={draft.channel}
                onChange={(channel) => update({ channel: channel as 'sale' | 'purchase' })}
              >
                <option value="sale">{t('Sales')}</option>
                <option value="purchase">{t('Purchases')}</option>
              </Select>
            )}
            <Select
              label={t('Quantity pricing method')}
              value={draft.pricing_scheme}
              onChange={(value) => update({ pricing_scheme: value as 'volume' | 'slab' })}
            >
              <option value="volume">{t('Volume: one rate for all units')}</option>
              <option value="slab">{t('Slab: price each range separately')}</option>
            </Select>
            <Check checked={draft.enabled} onChange={(enabled) => update({ enabled })}>
              {t('Use this list')}
            </Check>
            <Select
              label={t('Standard price adjustment')}
              value={draft.adjustment_mode}
              onChange={(mode) => update({ adjustment_mode: mode as 'increase' | 'decrease' })}
            >
              <option value="decrease">{t('Decrease')}</option>
              <option value="increase">{t('Increase')}</option>
            </Select>
            <Field
              label={t('Percentage')}
              name="adjustment_percent"
              inputMode="decimal"
              required
              value={draft.adjustment_percent}
              onChange={(event) => update({ adjustment_percent: event.target.value })}
            />
            <small>{t('Used when no item tier matches. Zero keeps standard price.')}</small>
            <OptionalDetails label={t('Valid BS dates (optional)')}>
              <Field
                label={t('Start day (BS)')}
                name="starts_bs"
                inputMode="numeric"
                value={draft.starts_bs}
                onChange={(event) => update({ starts_bs: event.target.value })}
              />
              <Field
                label={t('End day (BS)')}
                name="ends_bs"
                inputMode="numeric"
                value={draft.ends_bs}
                onChange={(event) => update({ ends_bs: event.target.value })}
              />
            </OptionalDetails>
          </fieldset>
        </section>
        <section className="panel" data-entry-step="1">
          <h2>{t('Item prices / tiers')}</h2>
          <p>
            {t(
              draft.pricing_scheme === 'slab'
                ? 'Each range uses its own price. Start every item at zero; the next minimum ends the previous range.'
                : 'Minimum 0 covers fractional quantities. Highest matching minimum prices all units. Prices exclude bill tax.',
            )}
          </p>
          <fieldset className="import-fields" disabled={locked}>
            {manage && (
              <Picker
                kind="items"
                priceChannel={draft.channel}
                onPick={(item) => add(item as Item)}
              />
            )}
            <p>
              {draft.rules.length} {t('price rules')}
            </p>
            {draft.rules.map((row, index) => (
              <div className="price-tier" key={index}>
                <strong>{row.item_name}</strong>
                <small>
                  {t('Billed base unit')}: {row.unit_snapshot} ({row.pos_unit})
                </small>
                {changedItem(row) && (
                  <p role="alert">
                    {t(
                      'Item changed or archived. Remove old tiers and add the current item again.',
                    )}
                  </p>
                )}
                <div className="form-grid">
                  <Field
                    label={`${t('Minimum quantity')} (${row.unit_snapshot})`}
                    name={`rules.${index}.min_qty`}
                    inputMode="decimal"
                    required
                    value={row.min_qty}
                    onChange={(event) => rule(index, { min_qty: event.target.value })}
                  />
                  <Field
                    label={t('Unit price (NPR)')}
                    name={`rules.${index}.price`}
                    inputMode="decimal"
                    required
                    value={row.price}
                    onChange={(event) => rule(index, { price: event.target.value })}
                  />
                </div>
                <div className="detail-actions">
                  <IonButton
                    fill="clear"
                    disabled={locked}
                    onClick={() => update({ rules: draft.rules.filter((_, i) => i !== index) })}
                  >
                    {t('Remove tier')}
                  </IonButton>
                  {draft.rules.findIndex((r) => r.item_id === row.item_id) === index && (
                    <IonButton
                      fill="outline"
                      aria-label={`${t('Add tier for')} ${row.item_name}`}
                      disabled={
                        locked || draft.rules.filter((r) => r.item_id === row.item_id).length >= 10
                      }
                      onClick={() => update({ rules: [...draft.rules, { ...row, min_qty: '' }] })}
                    >
                      {t('Add quantity tier')}
                    </IonButton>
                  )}
                </div>
              </div>
            ))}
          </fieldset>
        </section>
        <section className="panel" data-entry-step="2">
          <h2>{t('Review & save')}</h2>
          <p>
            <strong>{draft.name}</strong> · {t(draft.channel === 'sale' ? 'Sales' : 'Purchases')} ·{' '}
            {t(draft.enabled ? 'Enabled' : 'Disabled')}
          </p>
          <p>
            {t('Quantity pricing method')}: {t(draft.pricing_scheme === 'slab' ? 'Slab' : 'Volume')}
          </p>
          <p>
            {t(draft.adjustment_mode === 'decrease' ? 'Decrease' : 'Increase')}{' '}
            {draft.adjustment_percent}% · {draft.starts_bs || t('No start date')} →{' '}
            {draft.ends_bs || t('No end date')}
          </p>
          {draft.rules.map((row, index) => (
            <div className="mini-row" key={index}>
              <span>
                {row.item_name}
                <small>{rangeLabel(row)}</small>
              </span>
              <strong>
                {row.price} NPR / {row.unit_snapshot}
              </strong>
            </div>
          ))}
          <p>
            {t(
              'Assign to parties or choose while billing. Agreed party prices take priority. Saved bills keep original prices.',
            )}
          </p>
          <Errors error={form.error || localError} />
          {form.uncertain && (
            <IonButton disabled={form.busy} onClick={() => void form.retry()}>
              {t('Retry original action')}
            </IonButton>
          )}
          <Submit busy={form.busy} disabled={locked || badUnits || !draft.name.trim()}>
            {t('Save price list')}
          </Submit>
          {id && manage && (
            <IonButton
              fill="clear"
              disabled={locked}
              onClick={() => {
                if (
                  JSON.stringify(draft) !== initial.current &&
                  !window.confirm(t('Discard unsaved list changes?'))
                )
                  return;
                hydrated.current = '';
                result.reload();
              }}
            >
              {t('Reload saved list')}
            </IonButton>
          )}
        </section>
      </EntrySteps>
    </>
  );
}

export function PartyListAssignments({
  party,
  disabled = false,
}: {
  party: Party;
  disabled?: boolean;
}) {
  const { base, path, changed, t } = useWorkspace();
  const form = useSave();
  const [sale, setSale] = useState(party.sales_price_list_id || '');
  const [purchase, setPurchase] = useState(party.purchase_price_list_id || '');
  const version = useRef(party.trading_version);
  const baseline = useRef(JSON.stringify([sale, purchase]));
  const dirty = JSON.stringify([sale, purchase]) !== baseline.current;
  const locked = form.busy || form.uncertain || !!party.archived_at || disabled;
  function reload() {
    if (dirty && !window.confirm(t('Discard unsaved assignment?'))) return;
    setSale(party.sales_price_list_id || '');
    setPurchase(party.purchase_price_list_id || '');
    version.current = party.trading_version;
    baseline.current = JSON.stringify([
      party.sales_price_list_id || '',
      party.purchase_price_list_id || '',
    ]);
    form.clear();
  }
  return (
    <form
      className="panel narrow-form"
      data-dirty={dirty ? 'true' : 'false'}
      onSubmit={(event) => {
        event.preventDefault();
        if (locked) return;
        void form.save<Party>(
          `${base}/contacts/${party.id}/price-lists`,
          {
            version: version.current,
            sales_price_list_id: sale || null,
            purchase_price_list_id: purchase || null,
          },
          (row) => {
            version.current = row.trading_version;
            baseline.current = JSON.stringify([sale, purchase]);
            changed();
          },
          'PATCH',
        );
      }}
    >
      <h2>{t('Shared price lists')}</h2>
      <Link to={path + '/price-lists'}>{t('Manage price lists')}</Link>
      <Errors error={form.error} />
      {form.uncertain && (
        <IonButton disabled={form.busy} onClick={() => void form.retry()}>
          {t('Retry original action')}
        </IonButton>
      )}
      {party.is_customer && (
        <PriceListSelect
          channel="sale"
          label={t('Customer price list')}
          value={sale}
          onChange={setSale}
          disabled={locked}
        />
      )}{' '}
      {party.is_supplier && (
        <PriceListSelect
          channel="purchase"
          label={t('Supplier price list')}
          value={purchase}
          onChange={setPurchase}
          disabled={locked}
        />
      )}
      <p>
        {t(
          'Agreed item prices take priority. Disabled or out-of-date lists require review before billing.',
        )}
      </p>
      <Submit busy={form.busy} disabled={locked}>
        {t('Save list assignment')}
      </Submit>
      <IonButton fill="clear" disabled={locked} onClick={reload}>
        {t('Reload assignment')}
      </IonButton>
    </form>
  );
}
