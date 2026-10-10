import { IonButton } from '@ionic/react';
import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useWorkspace } from '../lib/context';
import { useData, useSave } from '../lib/api';
import { amount, bsDisplay, currency, digits, format, quantity } from '../lib/money';
import MeasurementUnits from '../components/MeasurementUnits';
import type { Item, ItemCategory } from '../lib/types';
import Picker from '../components/Picker';
import { Check, Errors, Field, Heading, Loading, Select, Submit } from '../components/ui';

type Kind = 'basket' | 'bundle' | 'buy_get' | 'items';
type Member = {
  item_id: string;
  unit_snapshot: string;
  pos_unit: string;
  item_kind: 'stock' | 'service';
};
type Rule = {
  item_id?: string;
  item_name?: string;
  item_ids?: string[];
  item_names?: string[];
  category_ids?: string[];
  category_names?: string[];
  item_snapshots?: Member[];
  role: 'component' | 'buy' | 'get';
  qty_milli: string;
  unit_snapshot: string;
  pos_unit: string;
  item_kind?: 'stock' | 'service';
};
type TargetRule = {
  role: 'target';
  item_ids: string[];
  item_names: string[];
  category_ids: string[];
  category_names: string[];
};
type Target = { id: string; name: string; type: 'item' | 'category' };
type RuleDraft = Omit<Rule, 'qty_milli'> & { qty: string };
export type BasketOffer = {
  id: string;
  name: string;
  enabled: boolean;
  offer_kind?: Kind;
  rules?: (Rule | TargetRule)[];
  maximum_applications?: number | null;
  discount_mode: 'fixed' | 'percent';
  discount_value: string;
  minimum_spend_paisa: string;
  maximum_discount_paisa: string | null;
  starts_bs?: number | null;
  ends_bs?: number | null;
  starts_minute?: number | null;
  ends_minute?: number | null;
  weekdays?: number[];
  available_now?: boolean;
  cashier_allowed: boolean;
  version: number;
};
type Draft = {
  name: string;
  enabled: boolean;
  offer_kind: Kind;
  rules: RuleDraft[];
  targets: Target[];
  maximum_applications: string;
  discount_mode: 'fixed' | 'percent';
  discount_value: string;
  minimum_spend: string;
  maximum_discount: string;
  starts_bs: string;
  ends_bs: string;
  starts_time: string;
  ends_time: string;
  weekdays: number[];
  cashier_allowed: boolean;
};
const blank: Draft = {
  name: '',
  enabled: true,
  offer_kind: 'basket',
  rules: [],
  targets: [],
  maximum_applications: '',
  discount_mode: 'fixed',
  discount_value: '',
  minimum_spend: '0',
  maximum_discount: '',
  starts_bs: '',
  ends_bs: '',
  starts_time: '',
  ends_time: '',
  weekdays: [],
  cashier_allowed: true,
};

const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
function clock(minute?: number | null) {
  return minute == null
    ? ''
    : String(Math.floor(minute / 60)).padStart(2, '0') + ':' + String(minute % 60).padStart(2, '0');
}
function scheduleText(row: BasketOffer, t: (text: string) => string) {
  return [
    row.starts_minute == null
      ? ''
      : clock(row.starts_minute) + '–' + clock(row.ends_minute) + ' ' + t('Nepal time'),
    row.weekdays?.length ? row.weekdays.map((day) => t(days[day])).join(', ') : '',
  ]
    .filter(Boolean)
    .join(' · ');
}
function fromOffer(row: BasketOffer): Draft {
  const selected = (row.rules || []).find((rule): rule is TargetRule => rule.role === 'target');
  return {
    name: row.name,
    enabled: !!row.enabled,
    offer_kind: row.offer_kind || 'basket',
    rules: (row.rules || [])
      .filter((rule): rule is Rule => rule.role !== 'target')
      .map(({ qty_milli, ...rule }) => ({ ...rule, qty: format(qty_milli, 3) })),
    targets: [
      ...(selected?.item_ids || []).map((id, i) => ({
        id,
        name: selected!.item_names[i] || id,
        type: 'item' as const,
      })),
      ...(selected?.category_ids || []).map((id, i) => ({
        id,
        name: selected!.category_names[i] || id,
        type: 'category' as const,
      })),
    ],
    maximum_applications: row.maximum_applications == null ? '' : String(row.maximum_applications),
    discount_mode: row.discount_mode,
    discount_value: format(row.discount_value),
    minimum_spend: format(row.minimum_spend_paisa),
    maximum_discount: row.maximum_discount_paisa == null ? '' : format(row.maximum_discount_paisa),
    starts_bs: row.starts_bs ? bsDisplay(row.starts_bs) : '',
    ends_bs: row.ends_bs ? bsDisplay(row.ends_bs) : '',
    starts_time: clock(row.starts_minute),
    ends_time: clock(row.ends_minute),
    weekdays: row.weekdays || [],
    cashier_allowed: !!row.cashier_allowed,
  };
}
function saving(row: BasketOffer, t: (text: string) => string) {
  return row.offer_kind === 'bundle'
    ? t('Price per bundle') + ': ' + currency(row.discount_value)
    : row.offer_kind === 'buy_get'
      ? t('Reward discount') + ': ' + format(row.discount_value) + '%'
      : row.discount_mode === 'percent'
        ? format(row.discount_value) + '%'
        : currency(row.discount_value);
}
function requirements(row: BasketOffer, t: (text: string) => string) {
  return (row.rules || [])
    .map((rule) =>
      rule.role === 'target'
        ? [
            ...rule.item_names,
            ...rule.category_names.map((name) => t('Category') + ': ' + name),
          ].join(', ')
        : (rule.role === 'buy' ? t('Buy') + ' ' : rule.role === 'get' ? t('Get') + ' ' : '') +
          format(rule.qty_milli, 3) +
          ' ' +
          rule.unit_snapshot +
          ' ' +
          (rule.item_id
            ? rule.item_name
            : t('Any of') +
              ' ' +
              [
                ...(rule.item_names || []),
                ...(rule.category_names || []).map((name) => t('Category') + ': ' + name),
              ].join(', ')),
    )
    .join(' + ');
}
export function BasketOffers() {
  const { base, path, business, revision, t } = useWorkspace();
  const result = useData<{ data: BasketOffer[] }>(base + '/basket-offers', revision);
  const manage = ['owner', 'manager', 'accountant'].includes(business.role);
  return (
    <>
      <Heading
        title={t('Basket offers')}
        description={t('Choose one offer; quantities use billed base units.')}
      >
        {manage && (
          <Link className="button-link" to={path + '/basket-offers/new'}>
            {t('New basket offer')}
          </Link>
        )}
      </Heading>
      {result.loading || result.error ? (
        <Loading error={result.error} retry={result.reload} />
      ) : (
        <div className="panel">
          {!result.data?.data.length && <p>{t('No basket offers yet.')}</p>}
          {result.data?.data.map((row) => (
            <div className="mini-row" key={row.id}>
              <div>
                <strong>{row.name}</strong>
                <small>
                  {saving(row, t)} · {t('Minimum spend')}: {currency(row.minimum_spend_paisa)}
                  {row.maximum_discount_paisa !== null && (
                    <>
                      {' '}
                      · {t('Maximum saving')}: {currency(row.maximum_discount_paisa)}
                    </>
                  )}
                  {row.maximum_applications != null && (
                    <>
                      {' '}
                      · {t('Maximum applications')}: {row.maximum_applications}
                    </>
                  )}
                  {!row.enabled && <> · {t('Disabled')}</>}
                </small>
                {row.rules?.length ? <small>{requirements(row, t)}</small> : null}
                {scheduleText(row, t) && <small>{scheduleText(row, t)}</small>}
                {row.enabled && row.available_now === false && (
                  <small>{t('Unavailable now')}</small>
                )}
              </div>
              {manage && <Link to={path + '/basket-offers/' + row.id}>{t('Edit')}</Link>}
            </div>
          ))}
        </div>
      )}
    </>
  );
}
export function BasketOfferSelect({
  value,
  onChange,
  disabled = false,
}: {
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
}) {
  const { base, revision, t, today } = useWorkspace();
  const result = useData<{ data: BasketOffer[] }>(base + '/basket-offers?enabled=1', revision);
  const offers = (result.data?.data || []).filter(
    (row) => (!row.starts_bs || row.starts_bs <= today) && (!row.ends_bs || row.ends_bs >= today),
  );
  const chosen = offers.find((row) => row.id === value);
  return (
    <>
      <Field label={t('Basket offer (optional)')}>
        <select value={value} onChange={(e) => onChange(e.target.value)} disabled={disabled}>
          <option value="">{t('No basket offer')}</option>
          {value && !offers.some((row) => row.id === value) && (
            <option value={value}>{t('Selected offer unavailable; remove or review')}</option>
          )}
          {offers.map((row) => (
            <option key={row.id} value={row.id} disabled={row.available_now === false}>
              {row.name} · {saving(row, t)} · {t('Minimum spend')}:{' '}
              {currency(row.minimum_spend_paisa)}
            </option>
          ))}
        </select>
      </Field>
      {chosen?.rules?.length ? (
        <p className="subtle">
          {requirements(chosen, t)}
          {chosen.maximum_applications != null && (
            <>
              {' '}
              · {t('Maximum applications')}: {chosen.maximum_applications}
            </>
          )}
          <br />
          {t(
            chosen.offer_kind === 'items'
              ? 'All matching quantities receive the saving.'
              : 'Add all required items, including rewards, to the cart.',
          )}
        </p>
      ) : null}
      {chosen && (scheduleText(chosen, t) || chosen.available_now === false) && (
        <p className="subtle">
          {scheduleText(chosen, t)}
          {chosen.available_now === false && (
            <>
              <br />
              {t('Offer unavailable now; remove or refresh availability.')}
            </>
          )}
          <br />
          <IonButton fill="clear" disabled={disabled} onClick={result.reload}>
            {t('Refresh offer availability')}
          </IonButton>
        </p>
      )}
      <Errors error={result.error} />
      {result.error && (
        <IonButton fill="clear" onClick={result.reload}>
          {t('Reload offers')}
        </IonButton>
      )}
      <small>
        {t('One offer per sale. Quantity prices apply first; offer saving is before tax.')}
      </small>
    </>
  );
}
export function BasketOfferEditor() {
  const { id } = useParams();
  const { base, path, business, changed, t } = useWorkspace();
  const result = useData<{ data: BasketOffer }>(id ? base + '/basket-offers/' + id : null);
  const [draft, setDraft] = useState<Draft>(blank);
  const [localError, setLocalError] = useState<Error>();
  const form = useSave();
  const navigate = useNavigate();
  const hydrated = useRef(false);
  const version = useRef<number | undefined>(undefined);
  const initial = useRef(JSON.stringify(blank));
  const locked = form.busy || form.uncertain;
  useEffect(() => {
    if (id && result.data?.data.id === id && !hydrated.current) {
      const next = fromOffer(result.data.data);
      setDraft(next);
      version.current = result.data.data.version;
      initial.current = JSON.stringify(next);
      hydrated.current = true;
    }
  }, [result.data, id]);
  if (!['owner', 'manager', 'accountant'].includes(business.role))
    return <p>{t('Offer setup requires owner, manager or accountant.')}</p>;
  if (id && (result.loading || result.error || result.data?.data.id !== id))
    return <Loading error={result.error} retry={result.reload} />;
  function update<K extends keyof Draft>(key: K, value: Draft[K]) {
    if (!locked) setDraft((row) => ({ ...row, [key]: value }));
  }
  function changeKind(kind: Kind) {
    if (!locked)
      setDraft((row) => ({
        ...row,
        offer_kind: kind,
        rules: [],
        targets: [],
        maximum_applications: '',
        maximum_discount: '',
        discount_mode: kind === 'buy_get' ? 'percent' : 'fixed',
        discount_value: kind === 'buy_get' ? '100' : '',
      }));
  }
  function addTarget(row: Item | ItemCategory, type: Target['type']) {
    if (!locked)
      setDraft((old) =>
        old.targets.some((target) => target.id === row.id && target.type === type) ||
        old.targets.filter((target) => target.type === type).length >= (type === 'item' ? 100 : 20)
          ? old
          : { ...old, targets: [...old.targets, { id: row.id, name: row.name, type }] },
      );
  }
  function add(item: Item, role: RuleDraft['role']) {
    if (locked) return;
    setDraft((row) => {
      if (
        role === 'component' &&
        (row.rules.length >= 20 || row.rules.some((rule) => rule.item_id === item.id))
      )
        return row;
      const rule: RuleDraft = {
        item_id: item.id,
        item_name: item.name,
        role,
        qty: '1',
        unit_snapshot: item.unit_label,
        pos_unit: item.pos_unit || 'unit',
        item_kind: item.kind,
      };
      return {
        ...row,
        rules: [...row.rules.filter((old) => role === 'component' || old.role !== role), rule],
      };
    });
  }
  function addGroup(role: RuleDraft['role']) {
    if (locked) return;
    setDraft((row) =>
      role === 'component' && row.rules.length >= 20
        ? row
        : {
            ...row,
            rules: [
              ...row.rules.filter((old) => role === 'component' || old.role !== role),
              {
                role,
                qty: '1',
                unit_snapshot: 'unit',
                pos_unit: 'unit',
                item_ids: [],
                item_names: [],
                category_ids: [],
                category_names: [],
                item_snapshots: [],
              },
            ],
          },
    );
  }
  function changeRule(rule: RuleDraft, change: Partial<RuleDraft>) {
    update(
      'rules',
      draft.rules.map((old) => (old === rule ? { ...old, ...change } : old)),
    );
  }
  function choose(rule: RuleDraft, row: Item | ItemCategory, type: Target['type']) {
    if (locked) return;
    setLocalError(undefined);
    if (type === 'category') {
      if (!rule.category_ids?.includes(row.id) && (rule.category_ids?.length || 0) < 20)
        changeRule(rule, {
          category_ids: [...(rule.category_ids || []), row.id],
          category_names: [...(rule.category_names || []), row.name],
        });
      return;
    }
    const item = row as Item,
      unit = item.pos_unit || 'unit';
    if (rule.item_ids?.includes(item.id) || (rule.item_ids?.length || 0) >= 100) return;
    if (unit !== rule.pos_unit && (rule.item_ids?.length || rule.category_ids?.length)) {
      setLocalError(new Error(t('Choice group items must share its billed base unit.')));
      return;
    }
    changeRule(rule, {
      pos_unit: unit,
      unit_snapshot: unit,
      item_ids: [...(rule.item_ids || []), item.id],
      item_names: [...(rule.item_names || []), item.name],
      item_snapshots: [
        ...(rule.item_snapshots || []),
        { item_id: item.id, unit_snapshot: item.unit_label, pos_unit: unit, item_kind: item.kind },
      ],
    });
  }
  function ruleEntry(rule: RuleDraft) {
    const index = draft.rules.indexOf(rule);
    return (
      <section
        className="panel"
        key={index}
        aria-label={rule.item_id ? rule.item_name : t('Choice group') + ' ' + (index + 1)}
      >
        <div className="mini-row">
          <strong>{rule.item_id ? rule.item_name : t('Choice group') + ' ' + (index + 1)}</strong>
          <button
            type="button"
            onClick={() =>
              update(
                'rules',
                draft.rules.filter((old) => old !== rule),
              )
            }
          >
            {t('Remove')}
          </button>
        </div>
        {!rule.item_id && (
          <>
            <p>{t('Choose items or categories sharing one base unit.')}</p>
            <Select
              label={t('Billed base unit')}
              value={rule.pos_unit}
              onChange={(value) =>
                changeRule(rule, {
                  pos_unit: value,
                  unit_snapshot: value,
                  item_ids: [],
                  item_names: [],
                  item_snapshots: [],
                })
              }
            >
              <MeasurementUnits />
            </Select>
            <details className="optional-details">
              <summary>{t('How choices count')}</summary>
              <p>
                {t(
                  'Any combination from these choices. Only items with the chosen billed base unit count; overlapping groups never reuse quantities.',
                )}
              </p>
              <p>
                {t(
                  'Changing base unit clears selected items. Categories remain filtered by that unit.',
                )}
              </p>
            </details>
            <Picker kind="items" onPick={(row) => choose(rule, row as Item, 'item')} />
            <Picker
              kind="item_categories"
              onPick={(row) => choose(rule, row as ItemCategory, 'category')}
            />
            {(rule.item_ids || []).map((id, i) => (
              <div className="mini-row" key={'i' + id}>
                <strong>{rule.item_names?.[i] || id}</strong>
                <button
                  type="button"
                  aria-label={t('Remove selection')}
                  onClick={() =>
                    changeRule(rule, {
                      item_ids: rule.item_ids!.filter((value) => value !== id),
                      item_names: rule.item_names!.filter((_, j) => i !== j),
                      item_snapshots: rule.item_snapshots?.filter(
                        (member) => member.item_id !== id,
                      ),
                    })
                  }
                >
                  {t('Remove')}
                </button>
              </div>
            ))}
            {(rule.category_ids || []).map((id, i) => (
              <div className="mini-row" key={'c' + id}>
                <strong>{t('Category') + ': ' + (rule.category_names?.[i] || id)}</strong>
                <button
                  type="button"
                  aria-label={t('Remove selection')}
                  onClick={() =>
                    changeRule(rule, {
                      category_ids: rule.category_ids!.filter((value) => value !== id),
                      category_names: rule.category_names!.filter((_, j) => i !== j),
                    })
                  }
                >
                  {t('Remove')}
                </button>
              </div>
            ))}
          </>
        )}
        <Field
          label={t('Required quantity') + ' (' + rule.unit_snapshot + ')'}
          inputMode="decimal"
          value={rule.qty}
          onChange={(e) => changeRule(rule, { qty: e.target.value })}
          required
        />
      </section>
    );
  }
  async function save() {
    if (locked) return;
    setLocalError(undefined);
    let applications: number | null = null;
    try {
      const value = amount(draft.discount_value),
        minimum = amount(draft.minimum_spend);
      const cap = draft.maximum_discount ? amount(draft.maximum_discount) : null;
      if (
        !draft.name.trim() ||
        value <= 0n ||
        minimum > 1000000000n ||
        (draft.discount_mode === 'percent' &&
          value > (['buy_get', 'items'].includes(draft.offer_kind) ? 10000n : 9999n)) ||
        (cap !== null && (cap <= 0n || draft.discount_mode !== 'percent'))
      )
        throw new Error(t('Check positive saving, minimum spend and valid percentage.'));
      if (draft.offer_kind === 'items' && !draft.targets.length)
        throw new Error(t('Choose at least one item or category.'));
      if (
        !!draft.starts_time !== !!draft.ends_time ||
        (!!draft.starts_time && draft.starts_time === draft.ends_time)
      )
        throw new Error(t('Enter both different start and end times.'));
      if (
        draft.offer_kind === 'bundle' &&
        (draft.rules.length < 2 ||
          draft.rules.length > 20 ||
          new Set(draft.rules.filter((rule) => rule.item_id).map((rule) => rule.item_id)).size !==
            draft.rules.filter((rule) => rule.item_id).length)
      )
        throw new Error(t('Choose 2–20 different bundle items.'));
      if (
        draft.offer_kind === 'buy_get' &&
        (draft.rules.length !== 2 ||
          !draft.rules.some((rule) => rule.role === 'buy') ||
          !draft.rules.some((rule) => rule.role === 'get'))
      )
        throw new Error(t('Choose buy item and reward item.'));
      if (
        draft.rules.some(
          (rule) => !rule.item_id && !rule.item_ids?.length && !rule.category_ids?.length,
        )
      )
        throw new Error(t('Choose at least one item or category.'));
      if (draft.rules.some((rule) => quantity(rule.qty) <= 0n))
        throw new Error(t('Required quantities must be positive.'));
      if (draft.maximum_applications) {
        const normalized = digits(draft.maximum_applications);
        if (
          !/^\d+$/.test(normalized) ||
          BigInt(normalized) < 1n ||
          BigInt(normalized) > 1000000000n
        )
          throw new Error(t('Maximum applications must be a positive whole number.'));
        applications = Number(normalized);
      }
    } catch (cause) {
      setLocalError(cause as Error);
      return;
    }
    const { targets, ...fields } = draft;
    await form.save<BasketOffer>(
      id ? base + '/basket-offers/' + id : base + '/basket-offers',
      {
        ...fields,
        rules:
          draft.offer_kind === 'items'
            ? [
                {
                  role: 'target',
                  item_ids: targets.filter((row) => row.type === 'item').map((row) => row.id),
                  category_ids: targets
                    .filter((row) => row.type === 'category')
                    .map((row) => row.id),
                },
              ]
            : draft.rules.map((rule) =>
                rule.item_id
                  ? {
                      item_id: rule.item_id,
                      role: rule.role,
                      qty: rule.qty,
                      unit_snapshot: rule.unit_snapshot,
                      pos_unit: rule.pos_unit,
                      item_kind: rule.item_kind,
                    }
                  : {
                      item_ids: rule.item_ids || [],
                      category_ids: rule.category_ids || [],
                      role: rule.role,
                      qty: rule.qty,
                      pos_unit: rule.pos_unit,
                      item_snapshots: rule.item_snapshots || [],
                    },
              ),
        starts_time: draft.starts_time || null,
        ends_time: draft.ends_time || null,
        maximum_applications: applications,
        version: id ? version.current : undefined,
        maximum_discount: draft.maximum_discount || null,
        starts_bs: draft.starts_bs || null,
        ends_bs: draft.ends_bs || null,
      },
      (row) => {
        version.current = row.version;
        initial.current = JSON.stringify(draft);
        changed();
        navigate(path + '/basket-offers/' + row.id);
      },
      id ? 'PATCH' : 'POST',
    );
  }
  return (
    <>
      <Heading
        title={t(id ? 'Edit basket offer' : 'New basket offer')}
        description={t('Choose one offer; quantities use billed base units.')}
      >
        <Link to={path + '/basket-offers'}>{t('Back')}</Link>
      </Heading>
      <form
        className="panel narrow-form"
        data-dirty={JSON.stringify(draft) !== initial.current ? 'true' : 'false'}
        onSubmit={(e) => {
          e.preventDefault();
          void save();
        }}
      >
        <fieldset disabled={locked}>
          <legend>{t('Saving')}</legend>
          <Field
            label={t('Offer name')}
            name="name"
            value={draft.name}
            maxLength={100}
            onChange={(e) => update('name', e.target.value)}
            required
          />
          <Select
            label={t('Offer type')}
            value={draft.offer_kind}
            onChange={(value) => changeKind(value as Kind)}
          >
            <option value="basket">{t('Whole basket saving')}</option>
            <option value="bundle">{t('Item bundle')}</option>
            <option value="buy_get">{t('Buy and get')}</option>
            <option value="items">{t('Selected items / categories')}</option>
          </Select>
          {['basket', 'items'].includes(draft.offer_kind) && (
            <Select
              label={t('Discount type')}
              value={draft.discount_mode}
              onChange={(value) =>
                setDraft((row) => ({
                  ...row,
                  discount_mode: value as Draft['discount_mode'],
                  maximum_discount: value === 'fixed' ? '' : row.maximum_discount,
                }))
              }
            >
              <option value="fixed">{t('Amount (NPR)')}</option>
              <option value="percent">{t('Percent (%)')}</option>
            </Select>
          )}
          <Field
            label={t(
              draft.offer_kind === 'bundle'
                ? 'Price per bundle (NPR)'
                : draft.offer_kind === 'buy_get'
                  ? 'Reward discount (%)'
                  : draft.discount_mode === 'percent'
                    ? 'Discount (%)'
                    : 'Saving (NPR)',
            )}
            inputMode="decimal"
            value={draft.discount_value}
            onChange={(e) => update('discount_value', e.target.value)}
            required
          />
          {draft.offer_kind === 'items' && (
            <>
              <h2>{t('Eligible items and categories')}</h2>
              <p>
                {t(
                  'All matching quantities receive the saving. Overlapping selections count once; other items keep their price.',
                )}
              </p>
              <Picker kind="items" onPick={(row) => addTarget(row as Item, 'item')} />
              <Picker kind="item_categories" onPick={(row) => addTarget(row, 'category')} />
              {draft.targets.map((target) => (
                <div className="mini-row" key={target.type + target.id}>
                  <strong>
                    {target.type === 'category' ? t('Category') + ': ' : ''}
                    {target.name}
                  </strong>
                  <button
                    type="button"
                    onClick={() =>
                      update(
                        'targets',
                        draft.targets.filter((old) => old !== target),
                      )
                    }
                  >
                    {t('Remove')}
                  </button>
                </div>
              ))}
            </>
          )}
          {draft.offer_kind === 'bundle' && (
            <>
              <h2>{t('Bundle items')}</h2>
              <p>
                {t(
                  'Every complete set uses the bundle price; extra quantities keep their normal price.',
                )}
              </p>
              <Picker kind="items" onPick={(row) => add(row as Item, 'component')} />
              <button
                type="button"
                disabled={draft.rules.length >= 20}
                onClick={() => addGroup('component')}
              >
                {t('Add choice group')}
              </button>
              {draft.rules.map(ruleEntry)}
            </>
          )}
          {draft.offer_kind === 'buy_get' && (
            <>
              <p>
                {t(
                  '100% makes only matched rewards free. Both buy and reward quantities must be in the cart.',
                )}
              </p>
              {(['buy', 'get'] as const).map((role) => (
                <section key={role} aria-label={t(role === 'buy' ? 'Buy item' : 'Reward item')}>
                  <h2>{t(role === 'buy' ? 'Buy item' : 'Reward item')}</h2>
                  <Picker kind="items" onPick={(row) => add(row as Item, role)} />
                  <button type="button" onClick={() => addGroup(role)}>
                    {t('Add choice group')}
                  </button>
                  {draft.rules.filter((rule) => rule.role === role).map(ruleEntry)}
                </section>
              ))}
            </>
          )}
          {['bundle', 'buy_get'].includes(draft.offer_kind) && (
            <Field
              label={t('Maximum applications (optional)')}
              inputMode="numeric"
              value={draft.maximum_applications}
              onChange={(e) => update('maximum_applications', e.target.value)}
            />
          )}
          <Field
            label={t('Minimum spend (NPR)')}
            inputMode="decimal"
            value={draft.minimum_spend}
            onChange={(e) => update('minimum_spend', e.target.value)}
            required
          />
          {draft.discount_mode === 'percent' && (
            <Field
              label={t('Maximum saving (NPR, optional)')}
              inputMode="decimal"
              value={draft.maximum_discount}
              onChange={(e) => update('maximum_discount', e.target.value)}
            />
          )}
          <details className="optional-details">
            <summary>{t('Nepal time schedule (optional)')}</summary>
            <div>
              <p>
                {t(
                  'Uses current Nepal time and BS date. Start included, end excluded. Overnight windows belong to their starting weekday.',
                )}
              </p>
              <Field
                label={t('Starts at (Nepal time, optional)')}
                type="time"
                value={draft.starts_time}
                onChange={(e) => update('starts_time', e.target.value)}
              />
              <Field
                label={t('Ends at (Nepal time, optional)')}
                type="time"
                value={draft.ends_time}
                onChange={(e) => update('ends_time', e.target.value)}
              />
              <p>{t('Leave days empty for every day.')}</p>
              {days.map((day, index) => (
                <Check
                  key={day}
                  checked={draft.weekdays.includes(index)}
                  onChange={(checked) =>
                    update(
                      'weekdays',
                      checked
                        ? [...draft.weekdays, index].sort()
                        : draft.weekdays.filter((value) => value !== index),
                    )
                  }
                >
                  {t(day)}
                </Check>
              ))}
            </div>
          </details>
          <details className="optional-details">
            <summary>{t('Validity and staff')}</summary>
            <div>
              <Field
                label={t('Starts (BS, optional)')}
                value={draft.starts_bs}
                onChange={(e) => update('starts_bs', e.target.value)}
              />
              <Field
                label={t('Ends (BS, optional)')}
                value={draft.ends_bs}
                onChange={(e) => update('ends_bs', e.target.value)}
              />
              <Check
                checked={draft.cashier_allowed}
                onChange={(value) => update('cashier_allowed', value)}
              >
                {t('Cashier may apply this offer')}
              </Check>
              <Check checked={draft.enabled} onChange={(value) => update('enabled', value)}>
                {t('Enabled')}
              </Check>
            </div>
          </details>
        </fieldset>
        <p>{t('One offer per sale. Quantity prices apply first; offer saving is before tax.')}</p>
        <Errors error={localError || form.error} />
        <Submit busy={form.busy} disabled={locked}>
          {t('Save basket offer')}
        </Submit>
        {form.uncertain && (
          <IonButton disabled={form.busy} onClick={() => void form.retry()}>
            {t('Retry original action')}
          </IonButton>
        )}
        {id && (
          <IonButton
            fill="clear"
            disabled={locked}
            onClick={() => {
              if (
                JSON.stringify(draft) !== initial.current &&
                !window.confirm(t('Reload and discard unsaved offer changes?'))
              )
                return;
              hydrated.current = false;
              result.reload();
            }}
          >
            {t('Reload offer')}
          </IonButton>
        )}
      </form>
    </>
  );
}
