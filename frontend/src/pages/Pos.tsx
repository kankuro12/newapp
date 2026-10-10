import { IonButton } from '@ionic/react';
import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useWorkspace } from '../lib/context';
import { request, useData, useLiveData, useSave } from '../lib/api';
import { amount, bsDisplay, currency, format, quantity } from '../lib/money';
import type { Doc, Item, Lookup, Page, Party } from '../lib/types';
import {
  Check,
  Empty,
  Errors,
  Field,
  Heading,
  Loading,
  OptionalDetails,
  Select,
  Status,
  Submit,
} from '../components/ui';
import Picker from '../components/Picker';
import { Pagination } from './Records';
import { CategoryFilter } from './CatalogTools';
import { ScanItem } from './BarcodeTools';
import { PriceListSelect } from './PriceLists';
import { BasketOfferSelect } from './BasketOffers';
import { measurementUnitLabel } from '../lib/measurement';

type Resource = {
  id: string;
  kind: 'table' | 'staff';
  name: string;
  start_minute: number;
  end_minute: number;
  active: boolean;
  version: number;
};
type Config = {
  profiles: string[];
  methods: string[];
  units: Record<string, [string, string, string]>;
  resources: Resource[];
};
type Measurement = {
  barcode?: string;
  barcode_fingerprint?: string;
  mode: string;
  value?: string;
  unit?: string;
  length?: string;
  length_unit?: string;
  width?: string;
  width_unit?: string;
  thickness?: string;
  thickness_unit?: string;
  pieces?: string;
  custom_index?: number;
};
type Cart = { item: Item; measurement: Measurement; note: string };
type Preview = {
  basket_offer?: { name: string; discount_paisa: string } | null;
  invoice_discount_paisa?: string;
  tax_paisa?: string;
  pricing?: { pricing_scheme: string }[];
  total_paisa: string;
  fingerprint: string;
  lines: {
    item_id: string;
    qty_milli: string;
    total_paisa: string;
    description?: string;
    gross_paisa?: string;
    before_offer_paisa?: string;
    line_discount_paisa?: string;
    unit_price_paisa?: string;
  }[];
};
type MenuLine = {
  item_id: string;
  name: string;
  qty_milli: string;
  unit_price_paisa: string;
  tax_bps: string;
  tax_category: string;
  note: string;
};
type Ticket = { id: string; status: string; lines: MenuLine[]; created_at: string };
type Order = {
  id: string;
  resource_id?: string;
  kind: string;
  resource_name: string;
  guest_name?: string;
  version: number;
  total_paisa: string;
  tickets: Ticket[];
};
type Booking = {
  id: string;
  resource_id: string;
  resource_name: string;
  version: number;
  client_name: string;
  phone?: string;
  contact_id?: string;
  business_date_bs: number;
  start_minute: number;
  end_minute: number;
  status: string;
  document_id?: string;
  notes?: string;
  services: {
    item_id: string;
    name: string;
    unit_price_paisa: string;
    tax_bps: string;
    tax_category: string;
    duration_minutes: number;
  }[];
};
const titles: Record<string, string> = {
  general: 'General store',
  meat: 'Meat shop',
  restaurant: 'Restaurant',
  barber: 'Barber shop',
  salon: 'Salon',
  milk: 'Milk sales',
  glass: 'Glass sales',
  wood: 'Wood sales',
};
const methodLabels: Record<string, string> = {
  quantity: 'Quantity',
  amount: 'Amount',
  pack: 'Pack / custom unit',
  length: 'Length',
  area: 'Area',
  volume: 'Cubic volume',
};
const time = (n: number) =>
  `${String(Math.floor(n / 60)).padStart(2, '0')}:${String(n % 60).padStart(2, '0')}`;
const minute = (value: string) => {
  const [h, m] = value.split(':').map(Number);
  return h * 60 + m;
};
function enabled(item: Item, config: Config): string[] {
  const family = config.units[item.pos_unit || 'unit']?.[0] || 'count';
  return (item.pos_methods || config.methods).filter(
    (mode) =>
      ['quantity', 'amount'].includes(mode) ||
      (mode === 'pack' && !!item.pos_custom_units?.length) ||
      mode === family,
  );
}
function initialMeasurement(item: Item, config: Config): Measurement {
  const available = enabled(item, config);
  const family = config.units[item.pos_unit || 'unit']?.[0];
  const mode =
    family && ['length', 'area', 'volume'].includes(family) && available.includes(family)
      ? family
      : available[0] || 'quantity';
  return {
    mode,
    value: '1',
    unit: item.pos_unit || 'unit',
    length: '1',
    width: '1',
    thickness: '1',
    length_unit: 'ft',
    width_unit: 'ft',
    thickness_unit: 'in',
    pieces: '1',
    custom_index: 0,
  };
}
function wireMeasurement(m: Measurement): Measurement {
  if (m.barcode)
    return {
      mode: m.mode,
      value: m.value,
      unit: m.unit,
      barcode: m.barcode,
      barcode_fingerprint: m.barcode_fingerprint,
    };
  if (['quantity', 'amount', 'pack'].includes(m.mode))
    return {
      mode: m.mode,
      value: m.value,
      ...(m.mode === 'quantity'
        ? { unit: m.unit }
        : m.mode === 'pack'
          ? { custom_index: m.custom_index }
          : {}),
    };
  return {
    mode: m.mode,
    length: m.length,
    length_unit: m.length_unit,
    pieces: m.pieces,
    ...(['area', 'volume'].includes(m.mode) ? { width: m.width, width_unit: m.width_unit } : {}),
    ...(m.mode === 'volume' ? { thickness: m.thickness, thickness_unit: m.thickness_unit } : {}),
  };
}
function describe(m: Measurement): string {
  if (['quantity', 'amount', 'pack'].includes(m.mode))
    return `${m.value} ${m.mode === 'amount' ? 'NPR' : m.mode === 'pack' ? 'packs' : m.unit || ''}`;
  return (
    [
      m.length + ' ' + m.length_unit,
      ...(['area', 'volume'].includes(m.mode) ? [m.width + ' ' + m.width_unit] : []),
      ...(m.mode === 'volume' ? [m.thickness + ' ' + m.thickness_unit] : []),
    ].join(' × ') + ` × ${m.pieces} pieces`
  );
}
function LiveStatus({ error, updatedAt }: { error?: Error; updatedAt?: number }) {
  const { t } = useWorkspace();
  return (
    <div className="pos-live" role="status">
      {error ? (
        <span className="error-text">
          {t('Live refresh unavailable')} · {error.message}
        </span>
      ) : (
        <span>
          ● {t('Live')} · {t('Refresh every 2 seconds')}
        </span>
      )}
      {updatedAt && (
        <small>
          {t('Last updated')}{' '}
          {new Date(updatedAt).toLocaleTimeString('en-GB', { timeZone: 'Asia/Kathmandu' })}
        </small>
      )}
    </div>
  );
}

export default function Pos() {
  const { base, path, business, t } = useWorkspace();
  const config = useData<{ data: Config }>(base + '/pos/config');
  const profile = business.pos_profile || 'general';
  if (config.loading || config.error) return <Loading error={config.error} retry={config.reload} />;
  return (
    <>
      <Heading
        eyebrow={t('COUNTER')}
        title={t(titles[profile] || 'POS')}
        description={t('Select → enter → review → save')}
      >
        <Link to={path + '/pos/setup'}>{t('POS setup')}</Link>
        <Link to={path + '/sales'}>{t('Sales')}</Link>
      </Heading>
      <div className="pos-branch">
        <span>
          {t('Branch')}: <strong>{business.name}</strong>
        </span>
        <Link to="/businesses">{t('Switch business')}</Link>
      </div>
      {profile === 'restaurant' ? (
        <Restaurant config={config.data!.data} />
      ) : ['barber', 'salon'].includes(profile) ? (
        <Appointments config={config.data!.data} />
      ) : (
        <Counter config={config.data!.data} profile={profile} />
      )}
    </>
  );
}
function Catalog({
  onPick,
  services = false,
}: {
  onPick: (item: Item) => void;
  services?: boolean;
}) {
  const { base, revision, t } = useWorkspace();
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const [category, setCategory] = useState<{ id: string; name: string }>();
  const result = useData<Page<Item>>(
    `${base}/items?q=${encodeURIComponent(q)}&page=${page}&category_id=${category?.id || ''}`,
    revision,
  );
  return (
    <section className="pos-catalog">
      <CategoryFilter
        value={category}
        onChange={(value) => {
          setCategory(value);
          setPage(1);
        }}
      />
      <Field
        label={t('Search / barcode')}
        value={q}
        autoComplete="off"
        placeholder={t('Name or exact SKU')}
        onChange={(e) => {
          setQ(e.target.value);
          setPage(1);
        }}
        onKeyDown={(e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            const exact =
              result.data?.data.filter(
                (item) => item.sku === q || item.aliases?.includes(q) || item.name === q,
              ) || [];
            if (exact.length === 1) onPick(exact[0]);
          }
        }}
      />
      {result.loading || result.error ? (
        <Loading error={result.error} retry={result.reload} />
      ) : (
        <>
          <div className="pos-products">
            {result.data?.data
              .filter((item) => !services || item.kind === 'service')
              .map((item) => (
                <button
                  type="button"
                  className="pos-product"
                  key={item.id}
                  onClick={() => onPick(item)}
                >
                  <span className="pos-product-symbol">{item.name.slice(0, 1)}</span>
                  <strong>{item.name}</strong>
                  <span>
                    {currency(item.sale_price_paisa)} / {item.unit_label}
                  </span>
                  <small>
                    {item.kind === 'stock'
                      ? `${format(item.qty_milli, 3)} ${item.unit_label}`
                      : `${item.service_minutes || 30} min`}
                  </small>
                </button>
              ))}
          </div>
          {!result.data?.data.length && <Empty title={t('Add products before selling')} />}
          <Pagination page={page} pages={result.data?.last_page || 1} change={setPage} />
        </>
      )}
    </section>
  );
}
function MeasurementEditor({
  item,
  config,
  value,
  onChange,
  profile,
}: {
  item: Item;
  config: Config;
  value: Measurement;
  onChange: (m: Measurement) => void;
  profile: string;
}) {
  const { t, path } = useWorkspace();
  const mode = value.mode;
  const family = config.units[item.pos_unit || 'unit']?.[0];
  const options = enabled(item, config);
  const units = Object.keys(config.units).filter((unit) => config.units[unit][0] === family);
  return (
    <>
      <div className="pos-methods">
        {options.map((key) => (
          <button
            type="button"
            aria-pressed={mode === key}
            key={key}
            onClick={() => onChange({ ...value, mode: key })}
          >
            {t(methodLabels[key])}
          </button>
        ))}
      </div>
      {['quantity', 'amount', 'pack'].includes(mode) ? (
        <>
          <div className="form-grid">
            <Field
              label={t(
                mode === 'amount'
                  ? 'Amount (NPR)'
                  : profile === 'meat'
                    ? 'Weight'
                    : profile === 'milk'
                      ? 'Milk quantity'
                      : 'Quantity',
              )}
              inputMode="decimal"
              value={value.value || ''}
              required
              onChange={(e) => onChange({ ...value, value: e.target.value })}
            />
            {mode === 'quantity' && (
              <Select
                label={t('Unit')}
                value={value.unit || item.pos_unit || 'unit'}
                onChange={(unit) => onChange({ ...value, unit })}
              >
                {units.map((unit) => (
                  <option key={unit} value={unit}>
                    {t(measurementUnitLabel(unit))}
                  </option>
                ))}
              </Select>
            )}
            {mode === 'pack' && (
              <Select
                label={t('Pack / custom unit')}
                value={String(value.custom_index || 0)}
                onChange={(index) => onChange({ ...value, custom_index: Number(index) })}
              >
                {item.pos_custom_units?.map((unit, index) => (
                  <option key={index} value={index}>
                    {unit.label} = {unit.qty} {item.unit_label}
                  </option>
                ))}
              </Select>
            )}
          </div>
          {mode === 'quantity' && ['kg', 'l'].includes(value.unit || '') && (
            <div className="pos-methods">
              {['0.25', '0.5', '1', '2'].map((qty) => (
                <button type="button" key={qty} onClick={() => onChange({ ...value, value: qty })}>
                  {qty} {value.unit}
                </button>
              ))}
            </div>
          )}
          {mode === 'amount' && (
            <p className="subtle">
              {t('Quantity rounds to 0.001 base unit. Confirm resulting total.')}
            </p>
          )}
        </>
      ) : (
        <>
          <div className="pos-dimensions">
            {[
              'length',
              ...(['area', 'volume'].includes(mode) ? ['width'] : []),
              ...(mode === 'volume' ? ['thickness'] : []),
            ].map((key) => (
              <div className="form-grid" key={key}>
                <Field
                  label={t(key[0].toUpperCase() + key.slice(1))}
                  inputMode="decimal"
                  value={String(value[key as keyof Measurement] || '')}
                  required
                  onChange={(e) => onChange({ ...value, [key]: e.target.value })}
                />
                <Select
                  label={t('Unit')}
                  value={String(value[(key + '_unit') as keyof Measurement] || 'ft')}
                  onChange={(unit) => onChange({ ...value, [key + '_unit']: unit })}
                >
                  {Object.keys(config.units)
                    .filter((unit) => config.units[unit][0] === 'length')
                    .map((unit) => (
                      <option key={unit} value={unit}>
                        {t(measurementUnitLabel(unit))}
                      </option>
                    ))}
                </Select>
              </div>
            ))}
          </div>
          <Field
            label={t('Pieces')}
            inputMode="decimal"
            value={value.pieces || ''}
            required
            onChange={(e) => onChange({ ...value, pieces: e.target.value })}
          />
        </>
      )}
      <small>
        {t('Billed base unit')}: {item.unit_label} ({item.pos_unit || 'unit'}).{' '}
        <Link to={path + '/items/' + item.id}>{t('Item measurement setup')}</Link>
      </small>
    </>
  );
}
function Counter({ config, profile }: { config: Config; profile: string }) {
  // Checkout uses a server proof for the current party, quantity prices, offer and date.
  const { base, path, today, t, changed } = useWorkspace();
  const [selected, setSelected] = useState<Item>();
  const [measurement, setMeasurement] = useState<Measurement>({ mode: 'quantity', value: '1' });
  const [note, setNote] = useState('');
  const [cart, setCart] = useState<Cart[]>([]);
  const [view, setView] = useState('products');
  const [proof, setProof] = useState<{ context: string; data: Preview }>();
  const [priceList, setPriceList] = useState('');
  const [basketOffer, setBasketOffer] = useState('');
  const [previewError, setPreviewError] = useState<Error>();
  const [party, setParty] = useState<Party>();
  const form = useSave();
  const navigate = useNavigate();
  const signature = JSON.stringify(
    cart.map((row) => ({
      item_id: row.item.id,
      measurement: wireMeasurement(row.measurement),
      note: row.note,
    })),
  );
  const context = JSON.stringify({
    signature,
    contact: party?.id,
    list: priceList,
    offer: basketOffer,
    date: today,
  });
  const preview = proof?.context === context ? proof.data : undefined;
  useEffect(() => {
    setProof(undefined);
    setPreviewError(undefined);
    if (signature === '[]') return;
    const abort = new AbortController();
    const timer = setTimeout(() => {
      void request<{ data: Preview }>(`${base}/pos/preview`, {
        method: 'POST',
        body: JSON.stringify({
          lines: JSON.parse(signature),
          contact_id: party?.id || null,
          price_list_id: priceList || null,
          basket_offer_id: basketOffer || null,
          business_date_bs: today,
        }),
        signal: abort.signal,
      })
        .then((response) => {
          if (!abort.signal.aborted) setProof({ context, data: response.data });
        })
        .catch((error) => {
          if (!abort.signal.aborted) setPreviewError(error);
        });
    }, 200);
    return () => {
      abort.abort();
      clearTimeout(timer);
    };
  }, [base, signature, party?.id, priceList, basketOffer, today, context]);
  return (
    <>
      <div className="pos-mobile-tabs">
        <button
          type="button"
          aria-pressed={view === 'products'}
          onClick={() => setView('products')}
        >
          {t('Products')}
        </button>
        <button type="button" aria-pressed={view === 'cart'} onClick={() => setView('cart')}>
          {t('Cart')} ({cart.length})
        </button>
      </div>
      <div
        className={`pos-counter pos-view-${view}`}
        data-dirty={cart.length || selected ? 'true' : 'false'}
      >
        <div className="pos-main">
          {!selected && (
            <ScanItem
              key={base}
              contactId={party?.id}
              priceListId={priceList}
              businessDate={today}
              disabled={form.busy || form.uncertain}
              onScan={(item, measurement) => {
                if (form.busy || form.uncertain) return;
                setCart((rows) => [...rows, { item, measurement, note: '' }]);
              }}
            />
          )}
          {!selected && (
            <Catalog
              onPick={(item) => {
                if (form.busy || form.uncertain) return;
                setSelected(item);
                setMeasurement(initialMeasurement(item, config));
                setNote('');
              }}
            />
          )}
          {selected && (
            <form
              className="panel pos-entry"
              onSubmit={(e) => {
                e.preventDefault();
                setCart((rows) => [
                  ...rows,
                  { item: selected, measurement: wireMeasurement(measurement), note },
                ]);
                setSelected(undefined);
                setNote('');
              }}
            >
              <h2>{selected.name}</h2>
              <p>
                {currency(selected.sale_price_paisa)} / {selected.unit_label}
              </p>
              <MeasurementEditor
                item={selected}
                config={config}
                value={measurement}
                onChange={setMeasurement}
                profile={profile}
              />
              <Field
                label={t(
                  profile === 'meat'
                    ? 'Cut / preparation notes'
                    : profile === 'glass' || profile === 'wood'
                      ? 'Cutting / job notes'
                      : 'Notes',
                )}
                maxLength={300}
                value={note}
                onChange={(e) => setNote(e.target.value)}
              />
              <div className="detail-actions">
                <IonButton type="submit">{t('Add to cart')}</IonButton>
                <IonButton fill="clear" onClick={() => setSelected(undefined)}>
                  {t('Cancel')}
                </IonButton>
              </div>
            </form>
          )}
        </div>
        <aside className="panel pos-cart">
          <h2>{t('Cart')}</h2>
          {cart.length ? (
            cart.map((row, index) => (
              <div className="pos-cart-row" key={index}>
                <div>
                  <strong>{row.item.name}</strong>
                  <small>{describe(row.measurement)}</small>
                  {row.note && <small>{row.note}</small>}
                </div>
                <button
                  type="button"
                  aria-label={`${t('Remove')} ${row.item.name}`}
                  disabled={form.busy || form.uncertain}
                  onClick={() => setCart((rows) => rows.filter((_, i) => i !== index))}
                >
                  ×
                </button>
              </div>
            ))
          ) : (
            <p>{t('Select a product to start.')}</p>
          )}
          <Errors error={previewError} />
          {cart.length > 0 && !preview && !previewError && (
            <p role="status">{t('Calculating trusted total…')}</p>
          )}
          {preview && (
            <>
              <div className="pos-calculated">
                {preview.basket_offer && <p>{t('Items before offer and tax')}</p>}
                {preview.pricing?.some((row) => row.pricing_scheme === 'slab') && (
                  <p>{t('Slab quantity breakdown')}</p>
                )}
                {preview.lines.map((line, index) => (
                  <div className="mini-row" key={`${line.item_id}-${index}`}>
                    <span>
                      {cart.find((row) => row.item.id === line.item_id)?.item.name}
                      <small>
                        {format(line.qty_milli, 3)}{' '}
                        {cart.find((row) => row.item.id === line.item_id)?.item.unit_label}
                        {line.unit_price_paisa && <> × {currency(line.unit_price_paisa)}</>}
                      </small>
                    </span>
                    <strong>
                      {currency(
                        preview.basket_offer
                          ? (line.before_offer_paisa ??
                              (line.gross_paisa !== undefined
                                ? BigInt(line.gross_paisa) - BigInt(line.line_discount_paisa || '0')
                                : line.total_paisa))
                          : line.total_paisa,
                      )}
                    </strong>
                  </div>
                ))}
              </div>
              {preview.basket_offer && (
                <div className="mini-row">
                  <span>
                    {preview.basket_offer.name}
                    <small>{t('Basket saving before tax')}</small>
                  </span>
                  <strong>−{currency(preview.basket_offer.discount_paisa)}</strong>
                </div>
              )}
              {preview.basket_offer && BigInt(preview.tax_paisa || '0') > 0n && (
                <div className="mini-row">
                  <span>{t('Tax')}</span>
                  <strong>{currency(preview.tax_paisa!)}</strong>
                </div>
              )}
              <div className="grand-total">
                <span>{t('Total')}</span>
                <strong>{currency(preview.total_paisa)}</strong>
              </div>
            </>
          )}
          <OptionalDetails label={t('Customer (optional)')}>
            <Picker kind="contacts" customer onPick={(row) => setParty(row as Party)} />
            {party && (
              <div className="mini-row">
                <strong>{party.name}</strong>
                <small>{t('Cart recalculates using this party’s agreed prices.')}</small>
                <button type="button" onClick={() => setParty(undefined)}>
                  ×
                </button>
              </div>
            )}
          </OptionalDetails>
          <PriceListSelect
            channel="sale"
            value={priceList}
            onChange={setPriceList}
            disabled={form.busy || form.uncertain}
          />
          <BasketOfferSelect
            value={basketOffer}
            onChange={setBasketOffer}
            disabled={form.busy || form.uncertain}
          />
          <Checkout
            total={preview?.total_paisa || '0'}
            date={today}
            busy={form.busy}
            error={form.error}
            retry={form.uncertain ? form.retry : undefined}
            disabled={!preview?.fingerprint || !cart.length}
            onCheckout={(paid, account) =>
              void form.save<Doc>(
                `${base}/pos/sales`,
                {
                  lines: JSON.parse(signature),
                  business_date_bs: today,
                  contact_id: party?.id || null,
                  price_list_id: priceList || null,
                  basket_offer_id: basketOffer || null,
                  expected_total_paisa: preview!.total_paisa,
                  expected_fingerprint: preview!.fingerprint,
                  paid_now: paid,
                  money_account_id: account,
                },
                (doc) => {
                  setCart([]);
                  changed();
                  navigate(path + '/document/' + doc.id);
                },
              )
            }
          />
        </aside>
      </div>
      {cart.length > 0 && view === 'products' && (
        <button type="button" className="pos-cart-dock" onClick={() => setView('cart')}>
          <span>
            {t('Cart')} · {cart.length}
          </span>
          <strong>{preview ? currency(preview.total_paisa) : t('Review')}</strong>
        </button>
      )}
    </>
  );
}
function Checkout({
  total,
  date,
  onCheckout,
  busy,
  error,
  retry,
  disabled = false,
}: {
  total: string;
  date: number;
  onCheckout: (paid: string, account: string) => void;
  busy: boolean;
  error?: Error;
  retry?: () => Promise<void>;
  disabled?: boolean;
}) {
  const { base, t } = useWorkspace();
  const lookup = useData<{ data: Lookup }>(base + '/lookup');
  const [account, setAccount] = useState('');
  const [partial, setPartial] = useState(false);
  const [paid, setPaid] = useState('0');
  let valid = true;
  try {
    valid = amount(partial ? paid : format(total)) <= BigInt(total);
  } catch {
    valid = false;
  }
  return (
    <form
      className="pos-checkout"
      onSubmit={(e) => {
        e.preventDefault();
        if (!disabled && valid)
          onCheckout(
            partial ? paid : format(total),
            account || lookup.data?.data.accounts[0]?.id || '',
          );
      }}
    >
      <Errors error={error || lookup.error} />
      {retry && (
        <IonButton disabled={busy} onClick={() => void retry()}>
          {t('Retry original action')}
        </IonButton>
      )}
      <p className="subtle">{bsDisplay(date)} BS · NPR</p>
      <Check checked={partial} onChange={setPartial}>
        {t('Partial payment / pay later')}
      </Check>
      {partial && (
        <Field
          label={t('Paid now')}
          inputMode="decimal"
          value={paid}
          onChange={(e) => setPaid(e.target.value)}
          required
        />
      )}
      <Select
        label={t('Payment account')}
        value={account || lookup.data?.data.accounts[0]?.id || ''}
        onChange={setAccount}
      >
        {lookup.data?.data.accounts.map((a) => (
          <option key={a.id} value={a.id}>
            {a.name}
          </option>
        ))}
      </Select>
      {partial && (
        <p className="subtle">
          {t('Pay later requires a selected customer. Walk-in must be fully paid.')}
        </p>
      )}
      <Submit busy={busy} disabled={disabled || !valid || !lookup.data}>
        {t('Save bill + payment')}
      </Submit>
    </form>
  );
}

export function ReviewedCheckout({
  endpoint,
  context,
  busy,
  disabled,
  uncertain,
  retry,
  error,
  onCheckout,
}: {
  endpoint: string;
  context: { version: number; business_date_bs: number; contact_id?: string | null };
  busy: boolean;
  disabled: boolean;
  uncertain: boolean;
  retry?: () => Promise<void>;
  error?: Error;
  onCheckout: (input: Record<string, unknown>) => void;
}) {
  const { t, revision } = useWorkspace();
  const [offer, setOffer] = useState('');
  const [refresh, setRefresh] = useState(0);
  const [review, setReview] = useState<{ key: string; data?: Preview; error?: Error }>();
  const signature = JSON.stringify({ ...context, basket_offer_id: offer || null });
  const key = endpoint + signature + ':' + revision + ':' + refresh;
  const current = review?.key === key ? review : undefined;
  const preview = disabled ? undefined : current?.data;
  const locked = busy || uncertain;
  useEffect(() => {
    const controller = new AbortController();
    if (disabled) return () => controller.abort();
    const timer = setTimeout(() => {
      request<{ data: Preview }>(endpoint + '/preview', {
        method: 'POST',
        body: signature,
        signal: controller.signal,
      })
        .then((result) => {
          if (!controller.signal.aborted) setReview({ key, data: result.data });
        })
        .catch((cause) => {
          if (!controller.signal.aborted) setReview({ key, error: cause as Error });
        });
    }, 200);
    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [endpoint, signature, key, disabled]);
  return (
    <div className="reviewed-checkout">
      <fieldset disabled={locked || disabled}>
        <legend>{t('Review & pay')}</legend>
        <BasketOfferSelect value={offer} onChange={setOffer} disabled={locked || disabled} />
        <Errors error={current?.error || error} />
        {!disabled && !preview && !current?.error && (
          <p role="status">{t('Calculating trusted total…')}</p>
        )}
        {preview && (
          <>
            {preview.basket_offer && (
              <>
                <p>{t('Items before offer and tax')}</p>
                {preview.lines.map((line, i) => (
                  <div className="mini-row" key={i}>
                    <span>
                      {line.description || line.item_id}
                      <small>{format(line.qty_milli, 3)}</small>
                    </span>
                    <strong>{currency(line.before_offer_paisa || '0')}</strong>
                  </div>
                ))}
                <div className="mini-row">
                  <span>
                    {preview.basket_offer.name}
                    <small>{t('Basket saving before tax')}</small>
                  </span>
                  <strong>−{currency(preview.basket_offer.discount_paisa)}</strong>
                </div>
                {BigInt(preview.tax_paisa || '0') > 0n && (
                  <div className="mini-row">
                    <span>{t('Tax')}</span>
                    <strong>{currency(preview.tax_paisa!)}</strong>
                  </div>
                )}
              </>
            )}
            <div className="grand-total">
              <span>{t('Total')}</span>
              <strong>{currency(preview.total_paisa)}</strong>
            </div>
          </>
        )}
        <IonButton
          fill="clear"
          disabled={locked || disabled}
          onClick={() => setRefresh((value) => value + 1)}
        >
          {t('Check total again')}
        </IonButton>
        <Checkout
          total={preview?.total_paisa || '0'}
          date={context.business_date_bs}
          busy={busy}
          disabled={disabled || uncertain || !preview?.fingerprint}
          onCheckout={(paid, account) => {
            if (preview && !locked)
              onCheckout({
                ...context,
                basket_offer_id: offer || null,
                expected_total_paisa: preview.total_paisa,
                expected_fingerprint: preview.fingerprint,
                paid_now: paid,
                money_account_id: account,
              });
          }}
        />
      </fieldset>
      {uncertain && retry && (
        <IonButton disabled={busy} onClick={() => void retry()}>
          {t('Retry original action')}
        </IonButton>
      )}
    </div>
  );
}

function Restaurant({ config }: { config: Config }) {
  const { base, path, revision, t, today, changed, business } = useWorkspace();
  const live = useLiveData<{ data: Order[] }>(base + '/restaurant/orders', revision);
  const [mode, setMode] = useState('waiter');
  const [orderId, setOrderId] = useState('');
  const [table, setTable] = useState('');
  const [guest, setGuest] = useState('');
  const [cart, setCart] = useState<{ item: Item; qty: string; note: string }[]>([]);
  const [party, setParty] = useState<Party>();
  const form = useSave();
  const cancelForm = useSave();
  const [reason, setReason] = useState('');
  const navigate = useNavigate();
  const order = live.data?.data.find((row) => row.id === orderId);
  const manager = ['owner', 'manager'].includes(business.role);
  const tables = config.resources.filter((row) => row.kind === 'table' && row.active);
  const allServed =
    !!order &&
    order.tickets.some((ticket) => ticket.status === 'served') &&
    order.tickets.every((ticket) => ['served', 'cancelled'].includes(ticket.status));

  function add(item: Item) {
    setCart((rows) => {
      const existing = rows.find((row) => row.item.id === item.id);
      if (!existing) return [...rows, { item, qty: '1', note: '' }];
      try {
        return rows.map((row) =>
          row.item.id === item.id ? { ...row, qty: format(quantity(row.qty) + 1000n, 3) } : row,
        );
      } catch {
        return rows;
      }
    });
  }
  return (
    <>
      <div className="pos-methods">
        {['waiter', 'kitchen'].map((key) => (
          <button type="button" key={key} aria-pressed={mode === key} onClick={() => setMode(key)}>
            {t(key === 'waiter' ? 'Waiter orders' : 'Kitchen display')}
          </button>
        ))}
      </div>
      <LiveStatus error={live.error} updatedAt={live.updatedAt} />
      <Errors error={form.error} />
      {form.uncertain && (
        <IonButton disabled={form.busy} onClick={() => void form.retry()}>
          {t('Retry original action')}
        </IonButton>
      )}
      {mode === 'kitchen' ? (
        <div className="kitchen-board">
          {['new', 'preparing', 'ready'].map((status) => (
            <section className="kitchen-column" key={status}>
              <h2>{t(status)}</h2>
              {live.data?.data.flatMap((row) =>
                row.tickets
                  .filter((ticket) => ticket.status === status)
                  .map((ticket) => (
                    <article className="panel kitchen-ticket" key={ticket.id}>
                      <h3>
                        {row.resource_name} · #{row.id}
                      </h3>
                      <small>
                        {row.guest_name} ·{' '}
                        {new Date(ticket.created_at).toLocaleTimeString('en-GB', {
                          timeZone: 'Asia/Kathmandu',
                        })}
                      </small>
                      {ticket.lines.map((line, i) => (
                        <div className="kitchen-item" key={i}>
                          <strong>
                            {format(line.qty_milli, 3)} × {line.name}
                          </strong>
                          {line.note && <p>{line.note}</p>}
                        </div>
                      ))}
                      <IonButton
                        disabled={form.busy || !!live.error}
                        onClick={() =>
                          void form.save(
                            `${base}/restaurant/orders/${row.id}/tickets/${ticket.id}`,
                            {
                              version: row.version,
                              status:
                                status === 'new'
                                  ? 'preparing'
                                  : status === 'preparing'
                                    ? 'ready'
                                    : 'served',
                            },
                            () => changed(),
                          )
                        }
                      >
                        {t(
                          status === 'new'
                            ? 'Start preparing'
                            : status === 'preparing'
                              ? 'Mark ready'
                              : 'Mark served',
                        )}
                      </IonButton>
                    </article>
                  )),
              )}
              <p className="subtle">{t('Tickets stay until status confirmed.')}</p>
            </section>
          ))}
        </div>
      ) : (
        <div className="pos-counter">
          <div>
            <div className="pos-table-grid">
              {tables.map((resource) => {
                const open = live.data?.data.find((row) => row.resource_id === resource.id);
                return (
                  <button
                    type="button"
                    key={resource.id}
                    aria-pressed={order?.resource_id === resource.id}
                    onClick={() => {
                      if (cart.length && !window.confirm('Discard unsent kitchen round?')) return;
                      if (open) {
                        setOrderId(open.id);
                        setCart([]);
                      } else {
                        setTable(resource.id);
                        setOrderId('');
                        setCart([]);
                      }
                    }}
                  >
                    <strong>{resource.name}</strong>
                    <small>{open ? `${t('Open')} #${open.id}` : t('Available')}</small>
                  </button>
                );
              })}
            </div>
            <Select
              label={t('Open order')}
              value={orderId}
              onChange={(id) => {
                if (cart.length && !window.confirm('Discard unsent kitchen round?')) return;
                setOrderId(id);
                setCart([]);
              }}
            >
              <option value="">{t('New order')}</option>
              {live.data?.data.map((row) => (
                <option key={row.id} value={row.id}>
                  {row.resource_name} · #{row.id} {row.guest_name}
                </option>
              ))}
            </Select>
            {!order ? (
              <form
                className="panel"
                onSubmit={(e) => {
                  e.preventDefault();
                  void form.save<Order>(
                    base + '/restaurant/orders',
                    {
                      kind: table ? 'dine_in' : 'takeaway',
                      resource_id: table || null,
                      guest_name: guest || null,
                    },
                    (row) => {
                      setOrderId(row.id);
                      setGuest('');
                      changed();
                    },
                  );
                }}
              >
                <h2>{t('Start order')}</h2>
                <Select label={t('Table / takeaway')} value={table} onChange={setTable}>
                  <option value="">{t('Takeaway')}</option>
                  {tables.map((row) => (
                    <option key={row.id} value={row.id}>
                      {row.name}
                    </option>
                  ))}
                </Select>
                <Field
                  label={t('Guest name (optional)')}
                  value={guest}
                  onChange={(e) => setGuest(e.target.value)}
                  maxLength={150}
                />
                <Submit busy={form.busy} disabled={!live.data || !!live.error}>
                  {t('Open order')}
                </Submit>
              </form>
            ) : (
              <>
                <h2>
                  {order.resource_name} · #{order.id}
                </h2>
                <Catalog onPick={add} />
                {cart.length > 0 && (
                  <form
                    className="panel"
                    data-dirty="true"
                    onSubmit={(e) => {
                      e.preventDefault();
                      void form.save<Order>(
                        `${base}/restaurant/orders/${order.id}/send`,
                        {
                          version: order.version,
                          lines: cart.map((row) => ({
                            item_id: row.item.id,
                            qty: row.qty,
                            note: row.note,
                          })),
                        },
                        () => {
                          setCart([]);
                          changed();
                        },
                      );
                    }}
                  >
                    <h3>{t('New kitchen round')}</h3>
                    {cart.map((row, i) => (
                      <div className="line-editor" key={row.item.id}>
                        <strong>{row.item.name}</strong>
                        <Field
                          label={t('Quantity')}
                          inputMode="decimal"
                          value={row.qty}
                          onChange={(e) =>
                            setCart((rows) =>
                              rows.map((r, index) =>
                                index === i ? { ...r, qty: e.target.value } : r,
                              ),
                            )
                          }
                        />
                        <Field
                          label={t('Preparation notes')}
                          value={row.note}
                          maxLength={300}
                          onChange={(e) =>
                            setCart((rows) =>
                              rows.map((r, index) =>
                                index === i ? { ...r, note: e.target.value } : r,
                              ),
                            )
                          }
                        />
                        <button
                          type="button"
                          onClick={() => setCart((rows) => rows.filter((_, index) => index !== i))}
                        >
                          {t('Remove')}
                        </button>
                      </div>
                    ))}
                    <Submit busy={form.busy} disabled={!!live.error}>
                      {t('Send to kitchen')}
                    </Submit>
                  </form>
                )}
              </>
            )}
          </div>
          <aside className="panel pos-cart">
            <h2>{t('Order tickets')}</h2>
            {order?.tickets.map((ticket) => (
              <div className="pos-order-ticket" key={ticket.id}>
                <Status value={ticket.status} />
                <small>#{ticket.id}</small>
                {ticket.lines.map((line, i) => (
                  <div key={i}>
                    <strong>
                      {format(line.qty_milli, 3)} × {line.name}
                    </strong>
                    {line.note && <small>{line.note}</small>}
                  </div>
                ))}
                {manager && ['new', 'preparing'].includes(ticket.status) && (
                  <OptionalDetails label={t('Cancel ticket')}>
                    <form
                      onSubmit={(e) => {
                        e.preventDefault();
                        void cancelForm.save(
                          `${base}/restaurant/orders/${order.id}/tickets/${ticket.id}`,
                          { version: order.version, status: 'cancelled', reason },
                          () => {
                            setReason('');
                            changed();
                          },
                        );
                      }}
                    >
                      <Errors error={cancelForm.error} />
                      {cancelForm.uncertain && (
                        <IonButton
                          disabled={cancelForm.busy}
                          onClick={() => void cancelForm.retry()}
                        >
                          {t('Retry original action')}
                        </IonButton>
                      )}
                      <Field
                        label={t('Reason')}
                        value={reason}
                        required
                        minLength={3}
                        maxLength={500}
                        onChange={(e) => setReason(e.target.value)}
                      />
                      <Submit busy={cancelForm.busy}>{t('Cancel ticket')}</Submit>
                    </form>
                  </OptionalDetails>
                )}
                {ticket.status === 'ready' && (
                  <IonButton
                    size="small"
                    disabled={form.busy || !!live.error}
                    onClick={() =>
                      void form.save(
                        `${base}/restaurant/orders/${order.id}/tickets/${ticket.id}`,
                        { version: order.version, status: 'served' },
                        () => changed(),
                      )
                    }
                  >
                    {t('Mark served')}
                  </IonButton>
                )}
              </div>
            ))}
            {order && (
              <>
                <OptionalDetails label={t('Customer (optional)')}>
                  <Picker kind="contacts" customer onPick={(row) => setParty(row as Party)} />
                  {party?.name}
                </OptionalDetails>
                <ReviewedCheckout
                  key={order.id}
                  endpoint={`${base}/restaurant/orders/${order.id}/checkout`}
                  context={{
                    version: order.version,
                    business_date_bs: today,
                    contact_id: party?.id || null,
                  }}
                  busy={form.busy}
                  uncertain={form.uncertain}
                  retry={form.retry}
                  disabled={!allServed || !!live.error || cart.length > 0}
                  onCheckout={(input) =>
                    void form.save<Doc>(
                      `${base}/restaurant/orders/${order.id}/checkout`,
                      input,
                      (doc) => {
                        changed();
                        navigate(path + '/document/' + doc.id);
                      },
                    )
                  }
                />
                {!allServed && <p>{t('Serve all tickets before checkout.')}</p>}
                {manager && (
                  <OptionalDetails label={t('Cancel open order')}>
                    <form
                      onSubmit={(e) => {
                        e.preventDefault();
                        void cancelForm.save(
                          `${base}/restaurant/orders/${order.id}/cancel`,
                          { version: order.version, reason },
                          () => {
                            setOrderId('');
                            setReason('');
                            changed();
                          },
                        );
                      }}
                    >
                      <Errors error={cancelForm.error} />
                      {cancelForm.uncertain && (
                        <IonButton
                          disabled={cancelForm.busy}
                          onClick={() => void cancelForm.retry()}
                        >
                          {t('Retry original action')}
                        </IonButton>
                      )}
                      <Field
                        label={t('Reason')}
                        value={reason}
                        required
                        minLength={3}
                        onChange={(e) => setReason(e.target.value)}
                      />
                      <Submit busy={cancelForm.busy}>{t('Cancel')}</Submit>
                    </form>
                  </OptionalDetails>
                )}
              </>
            )}
          </aside>
        </div>
      )}
    </>
  );
}

function Appointments({ config }: { config: Config }) {
  const { base, path, revision, t, today, changed } = useWorkspace();
  const [day, setDay] = useState(bsDisplay(today));
  const [filter, setFilter] = useState('');
  const live = useLiveData<{ data: Booking[] }>(
    `${base}/appointments?date=${encodeURIComponent(day)}`,
    revision,
  );
  const [view, setView] = useState('calendar');
  const [resource, setResource] = useState('');
  const [client, setClient] = useState('');
  const [phone, setPhone] = useState('');
  const [party, setParty] = useState<Party>();
  const [start, setStart] = useState('09:00');
  const [services, setServices] = useState<Item[]>([]);
  const [blocked, setBlocked] = useState(false);
  const [duration, setDuration] = useState('30');
  const [notes, setNotes] = useState('');
  const [selected, setSelected] = useState<Booking>();
  const [reschedule, setReschedule] = useState(false);
  const [moveDay, setMoveDay] = useState(day);
  const [moveStart, setMoveStart] = useState(start);
  const [moveStaff, setMoveStaff] = useState('');
  const form = useSave();
  const navigate = useNavigate();
  const staff = config.resources.filter((row) => row.kind === 'staff' && row.active);
  const fresh = live.data?.data.find((row) => row.id === selected?.id) || selected;

  function selectBooking(row: Booking) {
    setSelected(row);
    setReschedule(false);
    setMoveDay(bsDisplay(row.business_date_bs));
    setMoveStart(time(row.start_minute));
    setMoveStaff(row.resource_id);
  }
  return (
    <>
      <div className="pos-methods">
        {[
          ['calendar', 'Appointments'],
          ['new', 'Book appointment'],
          ['walk_in', 'Walk-in sale'],
        ].map(([key, label]) => (
          <button type="button" key={key} aria-pressed={view === key} onClick={() => setView(key)}>
            {t(label)}
          </button>
        ))}
      </div>
      {view === 'walk_in' ? (
        <Counter config={config} profile="salon" />
      ) : (
        <>
          <div className="form-grid">
            <Field
              label={t('Day (BS)')}
              inputMode="numeric"
              value={day}
              onChange={(e) => {
                setDay(e.target.value);
                setSelected(undefined);
              }}
            />
            <Select label={t('Staff / chair')} value={filter} onChange={setFilter}>
              <option value="">{t('All staff')}</option>
              {staff.map((row) => (
                <option key={row.id} value={row.id}>
                  {row.name}
                </option>
              ))}
            </Select>
          </div>
          <LiveStatus error={live.error} updatedAt={live.updatedAt} />
          <Errors error={form.error} />
          {form.uncertain && (
            <IonButton disabled={form.busy} onClick={() => void form.retry()}>
              {t('Retry original action')}
            </IonButton>
          )}
          {view === 'new' ? (
            <div className="pos-counter">
              <form
                className="panel"
                data-dirty={client || services.length ? 'true' : 'false'}
                onSubmit={(e) => {
                  e.preventDefault();
                  void form.save<Booking>(
                    base + '/appointments',
                    {
                      resource_id: resource || staff[0]?.id,
                      business_date_bs: day,
                      start_minute: minute(start),
                      client_name: client || 'Blocked time',
                      phone: phone || null,
                      contact_id: party?.id || null,
                      item_ids: blocked ? [] : services.map((row) => row.id),
                      status: blocked ? 'blocked' : 'booked',
                      duration_minutes: Number(duration),
                      notes: notes || null,
                    },
                    () => {
                      setView('calendar');
                      setClient('');
                      setPhone('');
                      setServices([]);
                      setParty(undefined);
                      changed();
                    },
                  );
                }}
              >
                <h2>{t('Book appointment')}</h2>
                <Select
                  label={t('Staff / chair')}
                  value={resource || staff[0]?.id || ''}
                  onChange={setResource}
                >
                  {staff.map((row) => (
                    <option key={row.id} value={row.id}>
                      {row.name} · {time(row.start_minute)}–{time(row.end_minute)}
                    </option>
                  ))}
                </Select>
                <Field
                  label={t('Start time (Nepal)')}
                  type="time"
                  value={start}
                  onChange={(e) => setStart(e.target.value)}
                  required
                />
                <Check checked={blocked} onChange={setBlocked}>
                  {t('Block personal / unavailable time')}
                </Check>
                {blocked ? (
                  <Field
                    label={t('Duration (minutes)')}
                    type="number"
                    min={1}
                    max={720}
                    value={duration}
                    onChange={(e) => setDuration(e.target.value)}
                  />
                ) : (
                  <>
                    <Field
                      label={t('Client name')}
                      value={client}
                      required
                      maxLength={150}
                      onChange={(e) => setClient(e.target.value)}
                    />
                    <Field
                      label={t('Phone (optional)')}
                      type="tel"
                      value={phone}
                      onChange={(e) => setPhone(e.target.value)}
                      maxLength={30}
                    />
                    <OptionalDetails label={t('Link customer for pay later')}>
                      <Picker
                        kind="contacts"
                        customer
                        onPick={(row) => {
                          setParty(row as Party);
                          setClient(row.name);
                          setPhone((row as Party).phone || '');
                        }}
                      />
                      {party?.name}
                    </OptionalDetails>
                    {services.map((row) => (
                      <div className="mini-row" key={row.id}>
                        <span>
                          {row.name}
                          <small>
                            {row.service_minutes || 30} min · {currency(row.sale_price_paisa)}
                          </small>
                        </span>
                        <button
                          type="button"
                          onClick={() =>
                            setServices((rows) => rows.filter((item) => item.id !== row.id))
                          }
                        >
                          ×
                        </button>
                      </div>
                    ))}
                    <strong>
                      {t('Duration')}:{' '}
                      {services.reduce((sum, row) => sum + (row.service_minutes || 30), 0)} min
                    </strong>
                  </>
                )}
                <Field
                  label={t('Notes')}
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  maxLength={1000}
                />
                <Submit
                  busy={form.busy}
                  disabled={!staff.length || (!blocked && !services.length) || !!live.error}
                >
                  {t('Save appointment')}
                </Submit>
              </form>
              {!blocked && (
                <Catalog
                  services
                  onPick={(item) =>
                    setServices((rows) =>
                      rows.some((row) => row.id === item.id) ? rows : [...rows, item],
                    )
                  }
                />
              )}
            </div>
          ) : (
            <>
              <div className="appointment-calendar">
                {staff
                  .filter((row) => !filter || row.id === filter)
                  .map((row) => (
                    <section className="panel appointment-column" key={row.id}>
                      <h2>{row.name}</h2>
                      <small>
                        {time(row.start_minute)}–{time(row.end_minute)} · Asia/Kathmandu
                      </small>
                      {live.data?.data
                        .filter((booking) => booking.resource_id === row.id)
                        .map((booking) => (
                          <button
                            type="button"
                            className={'appointment-card ' + booking.status}
                            key={booking.id}
                            onClick={() => selectBooking(booking)}
                          >
                            <strong>
                              {time(booking.start_minute)}–{time(booking.end_minute)} ·{' '}
                              {booking.client_name}
                            </strong>
                            <small>
                              {booking.services.map((service) => service.name).join(', ') ||
                                t('Blocked time')}
                            </small>
                            <Status value={booking.status} />
                          </button>
                        ))}
                      {!live.data?.data.some((booking) => booking.resource_id === row.id) && (
                        <p>{t('No appointments')}</p>
                      )}
                    </section>
                  ))}
              </div>
              {!staff.length && <Empty title={t('Add staff / chairs in POS setup')} />}
              <Link to={path + '/pos/setup'}>{t('POS setup')}</Link>
            </>
          )}
          {fresh && view === 'calendar' && (
            <section className="panel appointment-detail">
              <h2>
                {fresh.client_name} · {time(fresh.start_minute)}
              </h2>
              <p>
                {fresh.resource_name} · {fresh.phone} · {fresh.notes}
              </p>
              <Status value={fresh.status} />
              {fresh.document_id ? (
                <Link to={path + '/document/' + fresh.document_id}>{t('Open bill')}</Link>
              ) : (
                <>
                  <div className="detail-actions">
                    {fresh.status === 'booked' && (
                      <>
                        <IonButton
                          disabled={form.busy || !!live.error}
                          onClick={() =>
                            void form.save<Booking>(
                              `${base}/appointments/${fresh.id}`,
                              { version: fresh.version, status: 'arrived' },
                              (row) => {
                                setSelected(row);
                                changed();
                              },
                            )
                          }
                        >
                          {t('Mark arrived')}
                        </IonButton>
                        <IonButton
                          fill="outline"
                          disabled={form.busy || !!live.error}
                          onClick={() =>
                            void form.save<Booking>(
                              `${base}/appointments/${fresh.id}`,
                              { version: fresh.version, status: 'no_show' },
                              (row) => {
                                setSelected(row);
                                changed();
                              },
                            )
                          }
                        >
                          {t('No show')}
                        </IonButton>
                      </>
                    )}
                    {fresh.status === 'arrived' && (
                      <IonButton
                        disabled={form.busy || !!live.error}
                        onClick={() =>
                          void form.save<Booking>(
                            `${base}/appointments/${fresh.id}`,
                            { version: fresh.version, status: 'in_service' },
                            (row) => {
                              setSelected(row);
                              changed();
                            },
                          )
                        }
                      >
                        {t('Start service')}
                      </IonButton>
                    )}
                    {['booked', 'arrived', 'in_service', 'blocked'].includes(fresh.status) && (
                      <>
                        <IonButton
                          fill="outline"
                          disabled={form.busy}
                          onClick={() => setReschedule(!reschedule)}
                        >
                          {t('Reschedule')}
                        </IonButton>
                        <IonButton
                          fill="clear"
                          color="danger"
                          disabled={form.busy || !!live.error}
                          onClick={() =>
                            void form.save<Booking>(
                              `${base}/appointments/${fresh.id}`,
                              { version: fresh.version, status: 'cancelled' },
                              (row) => {
                                setSelected(row);
                                changed();
                              },
                            )
                          }
                        >
                          {t('Cancel')}
                        </IonButton>
                      </>
                    )}
                  </div>
                  {reschedule && (
                    <form
                      onSubmit={(e) => {
                        e.preventDefault();
                        void form.save(
                          `${base}/appointments/${fresh.id}`,
                          {
                            version: fresh.version,
                            resource_id: moveStaff,
                            business_date_bs: moveDay,
                            start_minute: minute(moveStart),
                          },
                          () => {
                            setSelected(undefined);
                            setReschedule(false);
                            setDay(moveDay);
                            changed();
                          },
                        );
                      }}
                    >
                      <Field
                        label={t('Day (BS)')}
                        value={moveDay}
                        required
                        onChange={(e) => setMoveDay(e.target.value)}
                      />
                      <Field
                        label={t('Start time (Nepal)')}
                        type="time"
                        value={moveStart}
                        required
                        onChange={(e) => setMoveStart(e.target.value)}
                      />
                      <Select label={t('Staff / chair')} value={moveStaff} onChange={setMoveStaff}>
                        {staff.map((row) => (
                          <option key={row.id} value={row.id}>
                            {row.name}
                          </option>
                        ))}
                      </Select>
                      <Submit busy={form.busy} disabled={!!live.error}>
                        {t('Save new time')}
                      </Submit>
                    </form>
                  )}
                  {['arrived', 'in_service'].includes(fresh.status) && (
                    <>
                      <ReviewedCheckout
                        key={fresh.id}
                        endpoint={`${base}/appointments/${fresh.id}/checkout`}
                        context={{
                          version: fresh.version,
                          business_date_bs: fresh.business_date_bs,
                        }}
                        busy={form.busy}
                        uncertain={form.uncertain}
                        retry={form.retry}
                        disabled={!!live.error}
                        onCheckout={(input) =>
                          void form.save<Doc>(
                            `${base}/appointments/${fresh.id}/checkout`,
                            input,
                            (doc) => {
                              changed();
                              navigate(path + '/document/' + doc.id);
                            },
                          )
                        }
                      />
                    </>
                  )}
                </>
              )}
            </section>
          )}
        </>
      )}
    </>
  );
}

export function PosSetup() {
  const { base, path, business, revision, t, changed } = useWorkspace();
  const config = useData<{ data: Config }>(base + '/pos/config', revision);
  const [profile, setProfile] = useState(business.pos_profile || 'general');
  const [branch, setBranch] = useState('');
  const [branchProfile, setBranchProfile] = useState(profile);
  const [kind, setKind] = useState('table');
  const [name, setName] = useState('');
  const [open, setOpen] = useState('09:00');
  const [close, setClose] = useState('20:00');
  const [edit, setEdit] = useState<Resource>();
  const profileForm = useSave();
  const branchForm = useSave();
  const resourceForm = useSave();
  const navigate = useNavigate();
  const owner = business.role === 'owner';
  const manager = ['owner', 'manager'].includes(business.role);
  if (config.loading || config.error) return <Loading error={config.error} retry={config.reload} />;
  return (
    <>
      <Heading
        title={t('POS setup')}
        description={t('Choose screen, units, tables and staff for this branch.')}
      >
        <Link to={path + '/pos'}>{t('POS')}</Link>
        <Link to={path + '/barcodes'}>{t('Product codes / scale setup')}</Link>
      </Heading>
      {owner && (
        <form
          className="panel narrow-form"
          onSubmit={(e) => {
            e.preventDefault();
            void profileForm.save(
              base + '/pos/profile',
              { pos_profile: profile },
              () => changed(),
              'PATCH',
            );
          }}
        >
          <h2>{t('Business type / POS screen')}</h2>
          <Errors error={profileForm.error} />
          {profileForm.uncertain && (
            <IonButton disabled={profileForm.busy} onClick={() => void profileForm.retry()}>
              {t('Retry original action')}
            </IonButton>
          )}
          <Select label={t('Business type')} value={profile} onChange={setProfile}>
            {config.data?.data.profiles.map((key) => (
              <option key={key} value={key}>
                {t(titles[key])}
              </option>
            ))}
          </Select>
          <Submit busy={profileForm.busy}>{t('Save screen')}</Submit>
        </form>
      )}
      <section className="panel narrow-form">
        <h2>{t('Measurements and service duration')}</h2>
        <p>
          {t(
            'Set base unit and enabled entry methods on each item. Add custom units such as bottle, bundle or sheet with their base quantity.',
          )}
        </p>
        <Link to={path + '/items'}>{t('Items')}</Link>
        <p className="subtle">
          {t(
            'Base unit cannot change after stock activity. All quantities round to 0.001 base unit.',
          )}
        </p>
      </section>
      {manager && (
        <>
          <form
            className="panel narrow-form"
            onSubmit={(e) => {
              e.preventDefault();
              void resourceForm.save(
                base + '/pos/resources' + (edit ? '/' + edit.id : ''),
                {
                  kind,
                  name,
                  start_minute: minute(open),
                  end_minute: minute(close),
                  active: edit?.active ?? true,
                  ...(edit ? { version: edit.version } : {}),
                },
                () => {
                  setEdit(undefined);
                  setName('');
                  changed();
                },
                edit ? 'PATCH' : 'POST',
              );
            }}
          >
            <h2>{t('Tables / staff / chairs')}</h2>
            <Errors error={resourceForm.error} />
            {resourceForm.uncertain && (
              <IonButton disabled={resourceForm.busy} onClick={() => void resourceForm.retry()}>
                {t('Retry original action')}
              </IonButton>
            )}
            <Select label={t('Type')} value={kind} onChange={setKind}>
              <option value="table">{t('Restaurant table')}</option>
              <option value="staff">{t('Staff / chair')}</option>
            </Select>
            <Field
              label={t('Name')}
              value={name}
              required
              maxLength={100}
              onChange={(e) => setName(e.target.value)}
            />
            {kind === 'staff' && (
              <div className="form-grid">
                <Field
                  label={t('Opening time')}
                  type="time"
                  value={open}
                  required
                  onChange={(e) => setOpen(e.target.value)}
                />
                <Field
                  label={t('Closing time')}
                  type="time"
                  value={close}
                  required
                  onChange={(e) => setClose(e.target.value)}
                />
              </div>
            )}
            <Submit busy={resourceForm.busy}>{t(edit ? 'Save' : 'Add resource')}</Submit>
            {edit && (
              <IonButton
                fill="clear"
                onClick={() => {
                  setEdit(undefined);
                  setName('');
                }}
              >
                {t('Cancel')}
              </IonButton>
            )}
          </form>
          <section className="panel narrow-form">
            {config.data?.data.resources.map((row) => (
              <div className="mini-row" key={row.id}>
                <span>
                  {row.name}
                  <small>
                    {row.kind} ·{' '}
                    {row.kind === 'staff'
                      ? `${time(row.start_minute)}–${time(row.end_minute)}`
                      : ''}{' '}
                    · {row.active ? t('Enabled') : t('Disabled')}
                  </small>
                </span>
                <IonButton
                  fill="clear"
                  onClick={() => {
                    setEdit(row);
                    setKind(row.kind);
                    setName(row.name);
                    setOpen(time(row.start_minute));
                    setClose(time(row.end_minute));
                  }}
                >
                  {t('Edit')}
                </IonButton>
                <IonButton
                  fill="clear"
                  disabled={resourceForm.busy}
                  onClick={() =>
                    void resourceForm.save(
                      base + '/pos/resources/' + row.id,
                      {
                        version: row.version,
                        kind: row.kind,
                        name: row.name,
                        start_minute: row.start_minute,
                        end_minute: row.end_minute,
                        active: !row.active,
                      },
                      () => changed(),
                      'PATCH',
                    )
                  }
                >
                  {t(row.active ? 'Disable' : 'Enable')}
                </IonButton>
              </div>
            ))}
          </section>
        </>
      )}
      {owner && !business.parent_tenant_id && (
        <form
          className="panel narrow-form"
          onSubmit={(e) => {
            e.preventDefault();
            void branchForm.save<{ slug: string }>(
              base + '/pos/branches',
              { name: branch, pos_profile: branchProfile },
              (row) => {
                changed();
                navigate('/app/' + row.slug + '/opening');
              },
            );
          }}
        >
          <h2>{t('Add branch')}</h2>
          <p>{t('Each branch has separate stock, cash, opening balances and staff access.')}</p>
          <Errors error={branchForm.error} />
          {branchForm.uncertain && (
            <IonButton disabled={branchForm.busy} onClick={() => void branchForm.retry()}>
              {t('Retry original action')}
            </IonButton>
          )}
          <Field
            label={t('Branch name')}
            value={branch}
            required
            maxLength={150}
            onChange={(e) => setBranch(e.target.value)}
          />
          <Select label={t('Business type')} value={branchProfile} onChange={setBranchProfile}>
            {config.data?.data.profiles.map((key) => (
              <option key={key} value={key}>
                {t(titles[key])}
              </option>
            ))}
          </Select>
          <Submit busy={branchForm.busy}>{t('Create branch')}</Submit>
        </form>
      )}
    </>
  );
}
