import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useWorkspace } from '../lib/context';
import { request, useData, useSave } from '../lib/api';
import { amount, bsDisplay, currency, format } from '../lib/money';
import type { Doc, Item, ItemCategory, Lookup, Page, Party, Workflow } from '../lib/types';
import { businessTypeLabel, jobTypes } from '../lib/businessTypes';
import { Errors, Loading } from '../components/ui';
import Picker from '../components/Picker';
import { HelpLink } from '../components/Help';
import { initialMeasurement, wireMeasurement } from '../lib/posMeasurement';
import { ScanItem } from './BarcodeTools';
import {
  MeasurementEditor,
  ReviewedCheckout,
  type Booking,
  type Config,
  type Measurement,
  type Order,
  type Preview,
} from './Pos';

type Line = { key: string; item: Item; measurement: Measurement; note: string };
type Held = { id: string; at: string; lines: Line[]; party?: Party };
type Overlay =
  | { kind: 'measure'; item: Item; measurement: Measurement; replace?: string }
  | { kind: 'customer' }
  | { kind: 'pay' }
  | { kind: 'held' }
  | { kind: 'order'; order: Order }
  | { kind: 'booking'; booking: Booking }
  | { kind: 'job'; job: Workflow }
  | undefined;

const PAGE = 25;
const heldKey = (slug: string) => `bb-held-${slug}`;
const readHeld = (slug: string): Held[] => {
  try {
    return JSON.parse(localStorage.getItem(heldKey(slug)) || '[]');
  } catch {
    return [];
  }
};
const writeHeld = (slug: string, rows: Held[]) => {
  try {
    localStorage.setItem(heldKey(slug), JSON.stringify(rows.slice(0, 20)));
  } catch {
    /* Parking is a convenience on this device only. */
  }
};
/** One-tap items: whole quantities in a count unit (piece, plate, job), as opposed to weight or length. */
const countable = (m: Measurement, config: Config) =>
  m.mode === 'quantity' && (config.units[m.unit || 'unit']?.[0] || 'count') === 'count';
const quantityText = (m: Measurement, item: Item) =>
  m.mode === 'amount'
    ? `NPR ${m.value}`
    : m.mode === 'quantity'
      ? `${m.value} ${!m.unit || m.unit === (item.pos_unit || 'unit') ? item.unit_label : m.unit}`
      : m.mode === 'pack'
        ? `${m.value} × ${item.pos_custom_units?.[m.custom_index || 0]?.label || 'pack'}`
        : `${m.pieces || 1} × ${[m.length, m.width, m.thickness].filter(Boolean).join(' × ')}`;

/** Full-screen counter for tablets and desktops; adapts its side modes to the business type. */
export default function FullPos() {
  const { base, path, business, today, t } = useWorkspace();
  const config = useData<{ data: Config }>(base + '/pos/config');
  const profile = business.pos_profile || 'general';
  const modes: [string, string][] = [
    ['sale', 'Sale'],
    ...(profile === 'restaurant' ? [['tables', 'Open tables'] as [string, string]] : []),
    ...(['barber', 'salon'].includes(profile)
      ? [['appointments', 'Appointments'] as [string, string]]
      : []),
    ...(profile === 'gym' ? [['checkin', 'Member check-in'] as [string, string]] : []),
    ...(jobTypes.includes(profile) ? [['jobs', 'Ready jobs'] as [string, string]] : []),
  ];
  const [mode, setMode] = useState('sale');
  return (
    <div className="full-pos">
      <div className="full-pos-mobile">
        <h2>{t('Full POS needs a tablet or desktop screen.')}</h2>
        <Link to={path + '/pos'}>{t('Open Quick POS')}</Link>
      </div>
      <header className="full-pos-top">
        <div className="full-pos-business">
          <span className="brand-mark">b</span>
          <strong>{business.name}</strong>
          <span className="badge active">{t(businessTypeLabel(profile))}</span>
          <span className="subtle">{bsDisplay(today)} BS</span>
        </div>
        <nav className="full-pos-modes" aria-label={t('POS modes')}>
          {modes.map(([key, label]) => (
            <button
              key={key}
              type="button"
              aria-pressed={mode === key}
              onClick={() => setMode(key)}
            >
              {t(label)}
            </button>
          ))}
        </nav>
        <div className="full-pos-exit">
          <HelpLink path={path} section="full-pos" label={t('Help')} />
          <Link to={path + '/pos'}>{t('Quick POS')}</Link>
          <Link to={path}>{t('Exit')}</Link>
        </div>
      </header>
      {config.loading || config.error ? (
        <Loading error={config.error} retry={config.reload} />
      ) : (
        <>
          {mode === 'sale' && <SaleMode config={config.data!.data} profile={profile} />}
          {mode === 'tables' && <TablesMode />}
          {mode === 'appointments' && <AppointmentsMode />}
          {mode === 'checkin' && <CheckinMode />}
          {mode === 'jobs' && <JobsMode />}
        </>
      )}
    </div>
  );
}

function SaleMode({ config, profile }: { config: Config; profile: string }) {
  const { base, path, today, t, changed, business } = useWorkspace();
  const [lines, setLines] = useState<Line[]>([]);
  const [party, setParty] = useState<Party>();
  const [overlay, setOverlay] = useState<Overlay>();
  const [proof, setProof] = useState<{ context: string; data: Preview }>();
  const [previewError, setPreviewError] = useState<Error>();
  const [done, setDone] = useState<Doc>();
  const [held, setHeld] = useState<Held[]>(() => readHeld(business.slug));
  const form = useSave();
  const navigate = useNavigate();
  const wire = useMemo(
    () =>
      lines.map((line) => ({
        item_id: line.item.id,
        measurement: wireMeasurement(line.measurement),
        note: line.note,
      })),
    [lines],
  );
  const context = JSON.stringify({ wire, contact: party?.id, today });
  const preview = proof?.context === context ? proof.data : undefined;
  useEffect(() => {
    setProof(undefined);
    setPreviewError(undefined);
    if (!wire.length) return;
    const abort = new AbortController();
    const timer = setTimeout(() => {
      void request<{ data: Preview }>(`${base}/pos/preview`, {
        method: 'POST',
        body: JSON.stringify({
          lines: wire,
          contact_id: party?.id || null,
          business_date_bs: today,
        }),
        signal: abort.signal,
      })
        .then((response) => !abort.signal.aborted && setProof({ context, data: response.data }))
        .catch((error) => !abort.signal.aborted && setPreviewError(error));
    }, 200);
    return () => {
      abort.abort();
      clearTimeout(timer);
    };
  }, [base, wire, party?.id, today, context]);
  const locked = form.busy || form.uncertain;
  function add(item: Item) {
    if (locked) return;
    setDone(undefined);
    const measurement = initialMeasurement(item, config);
    if (!countable(measurement, config)) {
      setOverlay({ kind: 'measure', item, measurement });
      return;
    }
    setLines((rows) => {
      const same = rows.find(
        (row) => row.item.id === item.id && countable(row.measurement, config) && !row.note,
      );
      if (same)
        return rows.map((row) =>
          row === same
            ? {
                ...row,
                measurement: {
                  ...row.measurement,
                  value: String(Number(row.measurement.value || 0) + 1),
                },
              }
            : row,
        );
      return [...rows, { key: crypto.randomUUID(), item, measurement, note: '' }];
    });
  }
  function step(key: string, delta: number) {
    setLines((rows) =>
      rows
        .map((row) =>
          row.key === key
            ? {
                ...row,
                measurement: {
                  ...row.measurement,
                  value: String(Number(row.measurement.value || 0) + delta),
                },
              }
            : row,
        )
        .filter((row) => Number(row.measurement.value) > 0),
    );
  }
  function park() {
    if (!lines.length) return;
    const next = [{ id: crypto.randomUUID(), at: new Date().toISOString(), lines, party }, ...held];
    setHeld(next);
    writeHeld(business.slug, next);
    setLines([]);
    setParty(undefined);
  }
  function pay(paid: string, account: string) {
    if (!preview) return;
    void form.save<Doc>(
      `${base}/pos/sales`,
      {
        lines: wire,
        business_date_bs: today,
        contact_id: party?.id || null,
        expected_total_paisa: preview.total_paisa,
        expected_fingerprint: preview.fingerprint,
        paid_now: paid,
        money_account_id: account || null,
      },
      (doc) => {
        setDone(doc);
        setLines([]);
        setParty(undefined);
        setOverlay(undefined);
        changed();
      },
    );
  }
  return (
    <div className="full-pos-body" data-dirty={lines.length ? 'true' : 'false'}>
      <ProductGrid
        onPick={add}
        disabled={locked}
        contactId={party?.id}
        onScan={(item, measurement) =>
          setLines((rows) => [...rows, { key: crypto.randomUUID(), item, measurement, note: '' }])
        }
      />
      <aside className="full-pos-ticket">
        <div className="full-pos-customer">
          <button type="button" onClick={() => setOverlay({ kind: 'customer' })} disabled={locked}>
            {party ? party.name : t('Walk-in customer')}
          </button>
          {party && (
            <button
              type="button"
              aria-label={t('Remove customer')}
              onClick={() => setParty(undefined)}
            >
              ×
            </button>
          )}
        </div>
        <div className="full-pos-lines">
          {lines.length ? (
            lines.map((line, index) => (
              <div className="full-pos-line" key={line.key}>
                <button
                  type="button"
                  className="full-pos-line-name"
                  onClick={() =>
                    setOverlay({
                      kind: 'measure',
                      item: line.item,
                      measurement: line.measurement,
                      replace: line.key,
                    })
                  }
                >
                  <strong>{line.item.name}</strong>
                  <small>{quantityText(line.measurement, line.item)}</small>
                </button>
                {countable(line.measurement, config) ? (
                  <span className="full-pos-stepper">
                    <button
                      type="button"
                      aria-label={t('Less')}
                      onClick={() => step(line.key, -1)}
                      disabled={locked}
                    >
                      −
                    </button>
                    <button
                      type="button"
                      aria-label={t('More')}
                      onClick={() => step(line.key, 1)}
                      disabled={locked}
                    >
                      +
                    </button>
                  </span>
                ) : (
                  <button
                    type="button"
                    aria-label={`${t('Remove')} ${line.item.name}`}
                    onClick={() => setLines((rows) => rows.filter((row) => row.key !== line.key))}
                    disabled={locked}
                  >
                    ×
                  </button>
                )}
                <span className="full-pos-line-total">
                  {preview?.lines[index] ? currency(preview.lines[index].total_paisa) : '…'}
                </span>
              </div>
            ))
          ) : done ? (
            <div className="full-pos-done" role="status">
              <strong>
                {t('Saved')} {done.number}
              </strong>
              <Link to={`${path}/document/${done.id}`}>{t('View / print bill')}</Link>
            </div>
          ) : (
            <p className="subtle">{t('Tap a product or scan a barcode to start.')}</p>
          )}
        </div>
        <Errors error={previewError || form.error} />
        <div className="full-pos-totals">
          {preview && BigInt(preview.tax_paisa || '0') > 0n && (
            <div className="mini-row">
              <span>{t('Tax')}</span>
              <strong>{currency(preview.tax_paisa!)}</strong>
            </div>
          )}
          <div className="grand-total">
            <span>{t('Total')}</span>
            <strong>
              {preview ? currency(preview.total_paisa) : lines.length ? '…' : currency('0')}
            </strong>
          </div>
        </div>
        <div className="full-pos-actions">
          <button type="button" onClick={park} disabled={!lines.length || locked}>
            {t('Park')}
          </button>
          <button type="button" onClick={() => setOverlay({ kind: 'held' })} disabled={locked}>
            {t('Recall')} ({held.length})
          </button>
          <button type="button" onClick={() => setLines([])} disabled={!lines.length || locked}>
            {t('Clear')}
          </button>
          {form.uncertain && (
            <button type="button" onClick={() => void form.retry()}>
              {t('Retry original action')}
            </button>
          )}
          <button
            type="button"
            className="full-pos-pay"
            disabled={!preview?.fingerprint || locked}
            onClick={() => setOverlay({ kind: 'pay' })}
          >
            {t('Pay')} {preview ? currency(preview.total_paisa) : ''}
          </button>
        </div>
      </aside>
      {overlay?.kind === 'measure' && (
        <Modal title={overlay.item.name} close={() => setOverlay(undefined)}>
          <form
            onSubmit={(e) => {
              e.preventDefault();
              const line = {
                key: overlay.replace || crypto.randomUUID(),
                item: overlay.item,
                measurement: wireMeasurement(overlay.measurement),
                note: '',
              };
              setLines((rows) =>
                overlay.replace
                  ? rows.map((row) => (row.key === overlay.replace ? line : row))
                  : [...rows, line],
              );
              setOverlay(undefined);
            }}
          >
            <MeasurementEditor
              item={overlay.item}
              config={config}
              value={overlay.measurement}
              onChange={(measurement) => setOverlay({ ...overlay, measurement })}
              profile={profile}
            />
            <button className="button" type="submit">
              {overlay.replace ? t('Update line') : t('Add to ticket')}
            </button>
          </form>
        </Modal>
      )}
      {overlay?.kind === 'customer' && (
        <Modal title={t('Customer')} close={() => setOverlay(undefined)}>
          <Picker
            kind="contacts"
            customer
            onPick={(row) => {
              setParty(row as Party);
              setOverlay(undefined);
            }}
          />
        </Modal>
      )}
      {overlay?.kind === 'pay' && preview && (
        <Modal
          title={`${t('Pay')} ${currency(preview.total_paisa)}`}
          close={() => setOverlay(undefined)}
        >
          <PayPanel total={preview.total_paisa} busy={form.busy} credit={!!party} onPay={pay} />
        </Modal>
      )}
      {overlay?.kind === 'held' && (
        <Modal title={t('Parked tickets')} close={() => setOverlay(undefined)}>
          {held.length ? (
            held.map((row) => (
              <div className="platform-row" key={row.id}>
                <div>
                  <strong>
                    {row.lines.length} {t('lines')} {row.party ? `· ${row.party.name}` : ''}
                  </strong>
                  <small>{new Date(row.at).toLocaleTimeString()}</small>
                </div>
                <button
                  type="button"
                  className="button"
                  onClick={() => {
                    if (lines.length) park();
                    const rest = held.filter((other) => other.id !== row.id);
                    setHeld(rest);
                    writeHeld(business.slug, rest);
                    setLines(row.lines);
                    setParty(row.party);
                    setOverlay(undefined);
                  }}
                >
                  {t('Recall')}
                </button>
              </div>
            ))
          ) : (
            <p>{t('No parked tickets.')}</p>
          )}
          <button type="button" onClick={() => navigate(path + '/sales')}>
            {t('All sales')}
          </button>
        </Modal>
      )}
    </div>
  );
}

/** Product tiles fill the panel and page instead of scrolling. */
function ProductGrid({
  onPick,
  onScan,
  disabled,
  contactId,
}: {
  onPick: (item: Item) => void;
  onScan: (item: Item, measurement: Measurement) => void;
  disabled: boolean;
  contactId?: string;
}) {
  const { base, today, t } = useWorkspace();
  const [q, setQ] = useState('');
  const [category, setCategory] = useState('');
  const [page, setPage] = useState(1);
  const categories = useData<Page<ItemCategory>>(`${base}/item-categories`);
  const items = useData<Page<Item>>(
    `${base}/items?q=${encodeURIComponent(q)}&page=${page}&category_id=${category}`,
  );
  return (
    <section className="full-pos-catalog">
      <div className="full-pos-search">
        <input
          aria-label={t('Search products')}
          placeholder={t('Search products')}
          value={q}
          onChange={(e) => {
            setQ(e.target.value);
            setPage(1);
          }}
        />
        <ScanItem contactId={contactId} businessDate={today} disabled={disabled} onScan={onScan} />
      </div>
      <div className="full-pos-categories">
        <button
          type="button"
          aria-pressed={!category}
          onClick={() => {
            setCategory('');
            setPage(1);
          }}
        >
          {t('All')}
        </button>
        {categories.data?.data.slice(0, 9).map((row) => (
          <button
            key={row.id}
            type="button"
            aria-pressed={category === row.id}
            onClick={() => {
              setCategory(row.id);
              setPage(1);
            }}
          >
            {row.name}
          </button>
        ))}
      </div>
      <div className="full-pos-grid">
        {items.loading || items.error ? (
          <Loading error={items.error} retry={items.reload} />
        ) : items.data?.data.length ? (
          items.data.data.slice(0, PAGE).map((item) => (
            <button
              key={item.id}
              type="button"
              className="full-pos-tile"
              disabled={disabled}
              onClick={() => onPick(item)}
            >
              <strong>{item.name}</strong>
              <span>
                {currency(item.sale_price_paisa || '0')}
                <small> / {item.unit_label}</small>
              </span>
            </button>
          ))
        ) : (
          <p className="subtle">{t('No products found.')}</p>
        )}
      </div>
      <div className="full-pos-pager">
        <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)}>
          ‹ {t('Previous')}
        </button>
        <span>
          {page} / {items.data?.last_page || 1}
        </span>
        <button
          type="button"
          disabled={page >= (items.data?.last_page || 1)}
          onClick={() => setPage(page + 1)}
        >
          {t('Next')} ›
        </button>
      </div>
    </section>
  );
}

/** Tendered amount with change, or a part/credit payment for a known customer. */
function PayPanel({
  total,
  busy,
  credit,
  onPay,
}: {
  total: string;
  busy: boolean;
  credit: boolean;
  onPay: (paid: string, account: string) => void;
}) {
  const { base, t } = useWorkspace();
  const lookup = useData<{ data: Lookup }>(base + '/lookup');
  const accounts = lookup.data?.data.accounts || [];
  const [account, setAccount] = useState('');
  const [tendered, setTendered] = useState(format(total));
  const chosen = account || accounts[0]?.id || '';
  let given = 0n;
  try {
    given = amount(tendered || '0');
  } catch {
    given = -1n;
  }
  const due = BigInt(total);
  const change = given > due ? given - due : 0n;
  const paid = given >= due ? format(total) : given >= 0n ? format(given.toString()) : '';
  const keys = ['7', '8', '9', '4', '5', '6', '1', '2', '3', '.', '0', '⌫'];
  return (
    <div className="full-pos-pay-panel">
      <Errors error={lookup.error} />
      <div className="full-pos-accounts">
        {accounts.map((row) => (
          <button
            key={row.id}
            type="button"
            aria-pressed={chosen === row.id}
            onClick={() => setAccount(row.id)}
          >
            {row.name}
          </button>
        ))}
      </div>
      <label className="field">
        <span>{t('Amount received (NPR)')}</span>
        <input inputMode="decimal" value={tendered} onChange={(e) => setTendered(e.target.value)} />
      </label>
      <div className="full-pos-quick">
        {[format(total), ...['500', '1000', '2000', '5000'].filter((note) => amount(note) > due)]
          .slice(0, 4)
          .map((note) => (
            <button key={note} type="button" onClick={() => setTendered(note)}>
              {note}
            </button>
          ))}
      </div>
      <div className="full-pos-keypad">
        {keys.map((key) => (
          <button
            key={key}
            type="button"
            onClick={() =>
              setTendered((value) =>
                key === '⌫' ? value.slice(0, -1) : value === format(total) ? key : value + key,
              )
            }
          >
            {key}
          </button>
        ))}
      </div>
      <div className="grand-total">
        <span>{given >= due ? t('Change') : t('Still due')}</span>
        <strong>
          {given >= due
            ? currency(change.toString())
            : given >= 0n
              ? currency((due - given).toString())
              : '—'}
        </strong>
      </div>
      {given >= 0n && given < due && !credit && (
        <p className="subtle">{t('Choose a customer to leave a balance due.')}</p>
      )}
      <button
        type="button"
        className="full-pos-pay"
        disabled={busy || given < 0n || (given < due && !credit)}
        onClick={() => onPay(paid, chosen)}
      >
        {given >= due ? t('Complete sale') : t('Save with balance due')}
      </button>
    </div>
  );
}

function TablesMode() {
  const { base, revision, t, today, changed } = useWorkspace();
  const orders = useData<{ data: Order[] }>(`${base}/restaurant/orders`, revision);
  const [open, setOpen] = useState<Order>();
  const form = useSave();
  return (
    <div className="full-pos-board">
      {orders.loading || orders.error ? (
        <Loading error={orders.error} retry={orders.reload} />
      ) : orders.data?.data.length ? (
        orders.data.data.map((order) => (
          <button
            key={order.id}
            type="button"
            className="full-pos-card"
            onClick={() => setOpen(order)}
          >
            <strong>{order.resource_name}</strong>
            <span>{order.guest_name || t('Guest')}</span>
            <span>
              {order.tickets.length} {t('rounds')} · {currency(order.total_paisa)}
            </span>
          </button>
        ))
      ) : (
        <p className="subtle">{t('No open tables. Take orders in Quick POS.')}</p>
      )}
      {open && (
        <Modal title={`${open.resource_name} · ${t('Checkout')}`} close={() => setOpen(undefined)}>
          <ReviewedCheckout
            endpoint={`${base}/restaurant/orders/${open.id}/checkout`}
            context={{ version: open.version, business_date_bs: today }}
            busy={form.busy}
            disabled={false}
            uncertain={form.uncertain}
            retry={form.retry}
            error={form.error}
            onCheckout={(input) =>
              void form.save(`${base}/restaurant/orders/${open.id}/checkout`, input, () => {
                setOpen(undefined);
                changed();
              })
            }
          />
        </Modal>
      )}
    </div>
  );
}

function AppointmentsMode() {
  const { base, revision, t, today, changed } = useWorkspace();
  const bookings = useData<{ data: Booking[] }>(`${base}/appointments?date=${today}`, revision);
  const [open, setOpen] = useState<Booking>();
  const form = useSave();
  const time = (minute: number) =>
    `${String(Math.floor(minute / 60)).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`;
  return (
    <div className="full-pos-board">
      {bookings.loading || bookings.error ? (
        <Loading error={bookings.error} retry={bookings.reload} />
      ) : bookings.data?.data.filter((row) => row.status !== 'blocked').length ? (
        bookings.data.data
          .filter((row) => row.status !== 'blocked')
          .map((row) => (
            <button
              key={row.id}
              type="button"
              className="full-pos-card"
              disabled={!['arrived', 'in_service'].includes(row.status)}
              onClick={() => setOpen(row)}
            >
              <strong>
                {time(row.start_minute)} · {row.client_name}
              </strong>
              <span>{row.resource_name}</span>
              <span className={`badge ${row.status}`}>{t(row.status.replace('_', ' '))}</span>
            </button>
          ))
      ) : (
        <p className="subtle">{t('No appointments today. Book in Quick POS.')}</p>
      )}
      {open && (
        <Modal title={`${open.client_name} · ${t('Checkout')}`} close={() => setOpen(undefined)}>
          <ReviewedCheckout
            endpoint={`${base}/appointments/${open.id}/checkout`}
            context={{ version: open.version, business_date_bs: today }}
            busy={form.busy}
            disabled={false}
            uncertain={form.uncertain}
            retry={form.retry}
            error={form.error}
            onCheckout={(input) =>
              void form.save(`${base}/appointments/${open.id}/checkout`, input, () => {
                setOpen(undefined);
                changed();
              })
            }
          />
        </Modal>
      )}
    </div>
  );
}

function CheckinMode() {
  const { base, revision, t, today, changed } = useWorkspace();
  const [q, setQ] = useState('');
  const members = useData<{
    data: {
      id: string;
      name: string;
      member_code: string;
      current: { end_bs: number; plan_snapshot: { name: string } } | null;
    }[];
  }>(`${base}/gym/members?q=${encodeURIComponent(q)}`, revision);
  const form = useSave();
  const [message, setMessage] = useState('');
  return (
    <div className="full-pos-board full-pos-checkin">
      <input
        aria-label={t('Find member by name, phone or code')}
        placeholder={t('Find member by name, phone or code')}
        value={q}
        onChange={(e) => setQ(e.target.value)}
        autoFocus
      />
      <Errors error={form.error} />
      {message && <p role="status">{message}</p>}
      <div className="full-pos-cards">
        {members.data?.data.slice(0, 20).map((member) => (
          <button
            key={member.id}
            type="button"
            className="full-pos-card"
            disabled={form.busy || !member.current}
            onClick={() =>
              void form.save(
                `${base}/gym/checkins`,
                {
                  member_id: member.id,
                  business_date_bs: today,
                  minute: new Date().getHours() * 60 + new Date().getMinutes(),
                },
                () => {
                  setMessage(`${member.name} ${t('checked in.')}`);
                  changed();
                },
              )
            }
          >
            <strong>{member.name}</strong>
            <span>{member.member_code}</span>
            <span>
              {member.current
                ? `${member.current.plan_snapshot.name} · ${t('until')} ${bsDisplay(member.current.end_bs)}`
                : t('No active membership')}
            </span>
          </button>
        ))}
      </div>
    </div>
  );
}

function JobsMode() {
  const { base, revision, t, today, changed } = useWorkspace();
  const jobs = useData<Page<Workflow>>(`${base}/workflows?kind=sales_order&status=ready`, revision);
  const [open, setOpen] = useState<Workflow>();
  const form = useSave();
  return (
    <div className="full-pos-board">
      {jobs.loading || jobs.error ? (
        <Loading error={jobs.error} retry={jobs.reload} />
      ) : jobs.data?.data.length ? (
        jobs.data.data.map((job) => (
          <button key={job.id} type="button" className="full-pos-card" onClick={() => setOpen(job)}>
            <strong>
              {job.number} · {job.title || job.reference || ''}
            </strong>
            <span>{job.party_snapshot.name}</span>
            <span>
              {Object.values(job.details || {})
                .slice(0, 2)
                .join(' · ')}{' '}
              · {currency(job.total_paisa)}
            </span>
          </button>
        ))
      ) : (
        <p className="subtle">{t('No jobs marked ready. Move jobs to Ready in Job cards.')}</p>
      )}
      {open && (
        <Modal title={`${open.number} · ${t('Deliver and bill')}`} close={() => setOpen(undefined)}>
          <PayPanel
            total={open.total_paisa}
            busy={form.busy}
            credit
            onPay={(paid, account) =>
              void form.save(
                `${base}/workflow/${open.id}/bill`,
                {
                  version: open.version,
                  business_date_bs: today,
                  paid_now: paid,
                  money_account_id: account || null,
                  expected_total_paisa: open.total_paisa,
                },
                () => {
                  setOpen(undefined);
                  changed();
                },
              )
            }
          />
          <Errors error={form.error} />
        </Modal>
      )}
    </div>
  );
}

function Modal({
  title,
  close,
  children,
}: {
  title: string;
  close: () => void;
  children: React.ReactNode;
}) {
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => event.key === 'Escape' && close();
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [close]);
  return (
    <div className="full-pos-modal" role="dialog" aria-modal="true" aria-label={title}>
      <div className="full-pos-modal-card">
        <header>
          <h2>{title}</h2>
          <button type="button" aria-label="Close" onClick={close}>
            ×
          </button>
        </header>
        <div className="full-pos-modal-body">{children}</div>
      </div>
    </div>
  );
}
