import { IonButton } from '@ionic/react';
import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useWorkspace } from '../lib/context';
import { request, useData, useOnline, useSave } from '../lib/api';
import type { Item } from '../lib/types';
import { Errors, Field, Heading, Loading, Select, Submit } from '../components/ui';
import Picker from '../components/Picker';
import EntrySteps from '../components/EntrySteps';
import MeasurementUnits from '../components/MeasurementUnits';

export interface ScanMeasurement {
  mode: string;
  value: string;
  unit?: string;
  barcode: string;
  barcode_fingerprint: string;
}
interface Rule {
  name: string;
  prefix: string;
  total_length: number;
  product_digits: number;
  value_digits: number;
  decimals: number;
  mode: 'quantity' | 'amount';
  unit: string;
}
interface Config {
  rules: Rule[];
  version: string;
}

export function ScanItem({
  onScan,
  disabled = false,
  contactId,
  priceListId,
  businessDate,
}: {
  onScan: (item: Item, measurement: ScanMeasurement) => void;
  disabled?: boolean;
  contactId?: string;
  priceListId?: string;
  businessDate?: number | string;
}) {
  const { base, t } = useWorkspace();
  const [code, setCode] = useState('');
  const [error, setError] = useState<Error>();
  const [busy, setBusy] = useState(false);
  const controller = useRef<AbortController | undefined>(undefined);
  const online = useOnline();
  const current = useRef({ onScan, disabled });
  current.current = { onScan, disabled };
  useEffect(() => {
    controller.current?.abort();
    controller.current = undefined;
    setBusy(false);
    return () => controller.current?.abort();
  }, [base, contactId, priceListId, businessDate]);
  async function scan() {
    if (disabled || busy || controller.current || !online || !code.trim()) return;
    const abort = new AbortController();
    controller.current = abort;
    setBusy(true);
    setError(undefined);
    try {
      const resolved = await request<{ data: { item_id: string; measurement: ScanMeasurement } }>(
        base + '/pos/scan',
        {
          method: 'POST',
          body: JSON.stringify({
            code: code.trim(),
            contact_id: contactId || null,
            ...(priceListId ? { price_list_id: priceListId } : {}),
            ...(businessDate ? { business_date_bs: businessDate } : {}),
          }),
          signal: abort.signal,
        },
      );
      const item = await request<{ data: Item }>(base + '/items/' + resolved.data.item_id, {
        signal: abort.signal,
      });
      if (!abort.signal.aborted && !current.current.disabled) {
        current.current.onScan(item.data, resolved.data.measurement);
        setCode('');
      }
    } catch (cause) {
      if (!abort.signal.aborted) setError(cause as Error);
    } finally {
      if (!abort.signal.aborted) {
        controller.current = undefined;
        setBusy(false);
      }
    }
  }
  return (
    <section className="panel scan-entry">
      <Errors error={error} />
      <Field
        label={t('Scan / enter product code')}
        value={code}
        autoComplete="off"
        maxLength={100}
        disabled={disabled}
        readOnly={busy}
        onChange={(event) => setCode(event.target.value)}
        onKeyDown={(event) => {
          if (event.key === 'Enter') {
            event.preventDefault();
            void scan();
          }
        }}
      />
      <IonButton
        fill="outline"
        disabled={disabled || busy || !online || !code.trim()}
        onClick={() => void scan()}
      >
        {t(busy ? 'Checking code…' : 'Add scanned item')}
      </IonButton>
      <small>
        {t('Keep leading zeros. Scale amounts may round; review trusted total before payment.')}
      </small>
    </section>
  );
}

export default function BarcodeTools() {
  const { base, path, business, t } = useWorkspace();
  const config = useData<{ data: Config }>(base + '/barcodes/config');
  if (config.loading || config.error) return <Loading error={config.error} retry={config.reload} />;
  return (
    <>
      <Heading
        title={t('Product codes / scale setup')}
        description={t(
          'Alternate codes identify one item. Enable only formats matching your scale labels.',
        )}
      >
        <Link to={path + '/pos'}>{t('POS')}</Link>
        <Link to={path + '/labels'}>{t('Product labels')}</Link>
      </Heading>
      {['owner', 'manager', 'accountant'].includes(business.role) ? (
        <BarcodeSettings
          key={config.data!.data.version}
          initial={config.data!.data}
          reload={config.reload}
        />
      ) : (
        <p>{t('Ask owner or manager to change product codes or scale formats.')}</p>
      )}
    </>
  );
}

function BarcodeSettings({ initial, reload }: { initial: Config; reload: () => void }) {
  const { base, path, t } = useWorkspace();
  const [item, setItem] = useState<Item>();
  const [aliases, setAliases] = useState('');
  const [rules, setRules] = useState(initial.rules);
  const [savedRules, setSavedRules] = useState(initial.rules);
  const [version, setVersion] = useState(initial.version);
  const [notice, setNotice] = useState('');
  const codesForm = useSave();
  const ruleForm = useSave();
  const locked = codesForm.busy || codesForm.uncertain || ruleForm.busy || ruleForm.uncertain;
  const dirty =
    (!!item && aliases !== (item.aliases || []).join('\n')) ||
    JSON.stringify(rules) !== JSON.stringify(savedRules);
  function update(index: number, patch: Partial<Rule>) {
    setRules((rows) => rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
  }
  return (
    <>
      <p role="status">{notice}</p>
      <EntrySteps
        labels={[t('Choose item'), t('Alternate product codes'), t('Review & save')]}
        t={t}
        dirty={dirty}
        busy={locked}
        error={codesForm.error}
        canContinue={[!!item, !!item]}
        onSubmit={(event) => {
          event.preventDefault();
          if (!item) return;
          void codesForm.save<{ aliases: string[]; version: string }>(
            base + '/barcodes/items/' + item.id,
            {
              version,
              aliases: aliases
                .split(/\r?\n/)
                .map((code) => code.trim())
                .filter(Boolean),
            },
            (result) => {
              setItem({ ...item, aliases: result.aliases });
              setAliases(result.aliases.join('\n'));
              setVersion(result.version);
              setNotice(t('Product codes saved'));
            },
          );
        }}
      >
        <section className="panel" data-entry-step="0">
          <h2>{t('Choose item')}</h2>
          <Picker
            kind="items"
            onPick={(row) => {
              if (locked) return;
              if (
                item &&
                aliases !== (item.aliases || []).join('\n') &&
                !window.confirm(t('Discard unsaved product codes?'))
              )
                return;
              setItem(row as Item);
              setAliases(((row as Item).aliases || []).join('\n'));
              codesForm.clear();
            }}
          />
          {item && <strong>{item.name}</strong>}
        </section>
        <section className="panel" data-entry-step="1">
          <h2>{t('Alternate product codes')}</h2>
          {item && (
            <p>
              {item.name} · {t('Primary SKU')}: {item.sku || '—'} ·{' '}
              <Link to={path + '/items/' + item.id}>{t('Item setup')}</Link>
            </p>
          )}
          <Field label={t('One alternate code per line')}>
            <textarea
              name="aliases"
              rows={6}
              value={aliases}
              disabled={locked}
              onChange={(event) => setAliases(event.target.value)}
              spellCheck={false}
            />
          </Field>
          <small>
            {t('Up to 20 codes. Preserve zeros. Each code must identify one item in this branch.')}
          </small>
          <IonButton
            fill="outline"
            disabled={locked || !item}
            onClick={() => {
              if (item)
                setAliases((codes) =>
                  [...new Set([...codes.split(/\r?\n/).filter(Boolean), 'BB' + item.id])].join(
                    '\n',
                  ),
                );
            }}
          >
            {t('Suggest item code')}
          </IonButton>
          <small>{t('Suggested internal code needs review and save before printing.')}</small>
        </section>
        <section className="panel" data-entry-step="2">
          <h2>{t('Review & save')}</h2>
          <strong>{item?.name}</strong>
          <p>
            {t('Primary SKU')}: {item?.sku || '—'}
          </p>
          <pre className="barcode-code-list">{aliases || t('No alternate codes')}</pre>
          <Errors error={codesForm.error} />
          {codesForm.uncertain && (
            <IonButton disabled={codesForm.busy} onClick={() => void codesForm.retry()}>
              {t('Retry original action')}
            </IonButton>
          )}
          <Submit busy={codesForm.busy} disabled={locked || !item}>
            {t('Save product codes')}
          </Submit>
          <p>{t('Changing codes changes no stock or money.')}</p>
        </section>
      </EntrySteps>
      <form
        className="panel section"
        data-dirty={dirty ? 'true' : 'false'}
        onSubmit={(event) => {
          event.preventDefault();
          void ruleForm.save<Config>(
            base + '/barcodes/config',
            { version, rules },
            (result) => {
              setVersion(result.version);
              setSavedRules(result.rules);
              setRules(result.rules);
              setNotice(t('Scale formats saved'));
            },
            'PATCH',
          );
        }}
      >
        <h2>{t('Scale label formats')}</h2>
        <Errors error={ruleForm.error} />
        {ruleForm.uncertain && (
          <IonButton disabled={ruleForm.busy} onClick={() => void ruleForm.retry()}>
            {t('Retry original action')}
          </IonButton>
        )}
        <p>
          {t(
            'Format: prefix + product digits + value digits + checksum. Match actual labels; direct scale connection is separate.',
          )}
        </p>
        <fieldset disabled={locked} className="import-fields">
          {rules.map((rule, index) => (
            <section className="line-editor" key={index}>
              <Field
                label={t('Format name')}
                value={rule.name}
                required
                maxLength={60}
                onChange={(event) => update(index, { name: event.target.value })}
              />
              <div className="form-grid">
                <Field
                  label={t('Prefix')}
                  value={rule.prefix}
                  required
                  inputMode="numeric"
                  maxLength={6}
                  pattern="[0-9]{1,6}"
                  onChange={(event) => update(index, { prefix: event.target.value })}
                />
                <Select
                  label={t('Barcode length')}
                  value={String(rule.total_length)}
                  onChange={(value) => update(index, { total_length: Number(value) })}
                >
                  <option value="13">EAN-13 · 13</option>
                  <option value="12">UPC-A · 12</option>
                </Select>
                <Field
                  label={t('Product digits')}
                  type="number"
                  value={rule.product_digits}
                  min={1}
                  max={9}
                  required
                  onChange={(event) =>
                    update(index, { product_digits: Number(event.target.value) })
                  }
                />
                <Field
                  label={t('Value digits')}
                  type="number"
                  value={rule.value_digits}
                  min={1}
                  max={9}
                  required
                  onChange={(event) => update(index, { value_digits: Number(event.target.value) })}
                />
                <Select
                  label={t('Encoded value')}
                  value={rule.mode}
                  onChange={(value) => update(index, { mode: value as Rule['mode'] })}
                >
                  <option value="quantity">{t('Quantity')}</option>
                  <option value="amount">{t('Amount (NPR)')}</option>
                </Select>
                <Field
                  label={t('Decimal places')}
                  type="number"
                  value={rule.decimals}
                  min={0}
                  max={rule.mode === 'amount' ? 2 : 3}
                  required
                  onChange={(event) => update(index, { decimals: Number(event.target.value) })}
                />
                {rule.mode === 'quantity' && (
                  <Select
                    label={t('Encoded quantity unit')}
                    value={rule.unit}
                    onChange={(unit) => update(index, { unit })}
                  >
                    <MeasurementUnits />
                  </Select>
                )}
              </div>
              <p>
                {t('Example encoded value')}:{' '}
                {rule.decimals === 0
                  ? '00375 → 375'
                  : rule.decimals === 1
                    ? '00375 → 37.5'
                    : rule.decimals === 2
                      ? '00375 → 3.75'
                      : '00375 → 0.375'}{' '}
                {rule.mode === 'amount' ? 'NPR' : rule.unit}
              </p>
              <IonButton
                fill="clear"
                disabled={locked}
                onClick={() => setRules((rows) => rows.filter((_, i) => i !== index))}
              >
                {t('Remove format')}
              </IonButton>
            </section>
          ))}
          {!rules.length && <p>{t('Scale decoding disabled. Exact product codes still work.')}</p>}
        </fieldset>
        <IonButton
          fill="outline"
          disabled={locked || rules.length >= 20}
          onClick={() =>
            setRules((rows) => [
              ...rows,
              {
                name: '',
                prefix: '20',
                total_length: 13,
                product_digits: 5,
                value_digits: 5,
                decimals: 3,
                mode: 'quantity',
                unit: 'kg',
              },
            ])
          }
        >
          {t('Add scale format')}
        </IonButton>
        <Submit busy={ruleForm.busy} disabled={locked}>
          {t('Save scale formats')}
        </Submit>
        <IonButton
          fill="clear"
          disabled={locked}
          onClick={() => {
            if (dirty && !window.confirm(t('Discard barcode setup changes and reload?'))) return;
            reload();
          }}
        >
          {t('Reload settings')}
        </IonButton>
      </form>
    </>
  );
}
