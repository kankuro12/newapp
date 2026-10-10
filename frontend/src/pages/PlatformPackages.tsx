import { IonButton } from '@ionic/react';
import { useState } from 'react';
import { useData, useSave } from '../lib/api';
import { Check, Errors, Field, Loading, Submit } from '../components/ui';
import { digits, format } from '../lib/money';
import type { PackageTerms } from './Billing';
const products = [
  'bookkeeping',
  'meat',
  'restaurant',
  'barber',
  'salon',
  'milk',
  'glass',
  'wood',
  'gym',
];
function minorUnits(value: string): string {
  const normalized = digits(value);
  if (!/^\d+(?:\.\d{1,2})?$/.test(normalized))
    throw new Error('Enter a positive price with up to two decimal places.');
  const [whole, fraction = ''] = normalized.split('.');
  const result = BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
  if (result < 1n || result > 999999999999n) throw new Error('Price outside supported range.');
  return result.toString();
}
export default function PlatformPackages() {
  const catalogue = useData<{ data: PackageTerms[] }>('/api/platform/packages');
  const form = useSave();
  const [editing, setEditing] = useState<PackageTerms | null | undefined>();
  const [name, setName] = useState('');
  const [duration, setDuration] = useState('30');
  const [trial, setTrial] = useState('14');
  const [limit, setLimit] = useState('1');
  const [selected, setSelected] = useState<string[]>(['bookkeeping']);
  const [active, setActive] = useState(true);
  const [prices, setPrices] = useState<Record<string, string>>({ NPR: '', USD: '', EUR: '' });
  const [password, setPassword] = useState('');
  const [reason, setReason] = useState('');
  const [error, setError] = useState<Error>();
  const blocked = form.busy || form.uncertain;
  function edit(plan: PackageTerms | null) {
    setEditing(plan);
    setName(plan?.name || '');
    setDuration(String(plan?.duration_days ?? 30));
    setTrial(String(plan?.trial_days ?? 14));
    setLimit(String(plan?.max_businesses ?? 1));
    setSelected(plan?.products || ['bookkeeping']);
    setActive(plan?.active ?? true);
    setPrices(
      Object.fromEntries(
        ['NPR', 'USD', 'EUR'].map((currency) => [
          currency,
          plan?.prices.find((price) => price.currency === currency)
            ? format(plan.prices.find((price) => price.currency === currency)!.amount_minor)
            : '',
        ]),
      ),
    );
    setPassword('');
    setReason('');
    setError(undefined);
  }
  return (
    <details className="panel section">
      <summary>Manage packages</summary>
      {catalogue.loading || catalogue.error ? (
        <Loading error={catalogue.error} retry={catalogue.reload} />
      ) : (
        <>
          {catalogue.data?.data.map((plan) => (
            <div className="platform-row" key={plan.id}>
              <div>
                <strong>{plan.name}</strong>
                <small>
                  {plan.duration_days} days · {plan.trial_days} trial days · {plan.max_businesses}{' '}
                  businesses
                </small>
              </div>
              <IonButton disabled={blocked} onClick={() => edit(plan)}>
                Edit {plan.name}
              </IonButton>
            </div>
          ))}
          <IonButton disabled={blocked} onClick={() => edit(null)}>
            New package
          </IonButton>
        </>
      )}
      {editing !== undefined && (
        <form
          className="narrow-form"
          onSubmit={(event) => {
            event.preventDefault();
            setError(undefined);
            try {
              const payload = {
                name,
                duration_days: Number(duration),
                trial_days: Number(trial),
                max_businesses: Number(limit),
                products: selected,
                active,
                version: editing?.version ?? 1,
                prices: Object.entries(prices)
                  .filter(([, value]) => value.trim())
                  .map(([currency, value]) => ({ currency, amount_minor: minorUnits(value) })),
                password,
                reason,
              };
              void form.save(
                `/api/platform/packages${editing ? '/' + editing.id : ''}`,
                payload,
                () => {
                  setEditing(undefined);
                  setPassword('');
                  catalogue.reload();
                },
                editing ? 'PATCH' : 'POST',
              );
            } catch (caught) {
              setError(caught as Error);
            }
          }}
        >
          <Errors error={error || form.error} />
          <fieldset disabled={blocked}>
            <Field
              label="Package name"
              required
              maxLength={150}
              value={name}
              onChange={(e) => setName(e.target.value)}
            />
            <Field
              label="Duration days"
              type="number"
              min="1"
              max="3650"
              required
              value={duration}
              onChange={(e) => setDuration(e.target.value)}
            />
            <Field
              label="Trial days"
              type="number"
              min="0"
              max="365"
              required
              value={trial}
              onChange={(e) => setTrial(e.target.value)}
            />
            <Field
              label="Business limit"
              type="number"
              min="1"
              max="10000"
              required
              value={limit}
              onChange={(e) => setLimit(e.target.value)}
            />
            {products.map((product) => (
              <Check
                key={product}
                checked={selected.includes(product)}
                onChange={(checked) =>
                  setSelected((current) =>
                    checked ? [...current, product] : current.filter((value) => value !== product),
                  )
                }
              >
                {product}
              </Check>
            ))}
            {Object.entries(prices).map(([currency, value]) => (
              <Field
                key={currency}
                label={`${currency} price`}
                inputMode="decimal"
                value={value}
                onChange={(e) =>
                  setPrices((current) => ({ ...current, [currency]: e.target.value }))
                }
              />
            ))}
            <p>Set at least one currency price. Saved subscriptions retain their original terms.</p>
            <Check checked={active} onChange={setActive}>
              Available
            </Check>
            <Field
              label="Change reason"
              required
              minLength={5}
              maxLength={500}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
            />
            <Field
              label="Confirm admin password"
              type="password"
              autoComplete="current-password"
              required
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
            <Submit busy={form.busy} disabled={blocked}>
              Save package
            </Submit>
            <IonButton
              fill="clear"
              disabled={blocked}
              onClick={() => {
                setEditing(undefined);
                setPassword('');
              }}
            >
              Cancel
            </IonButton>
          </fieldset>
          {form.uncertain && (
            <IonButton disabled={form.busy} onClick={() => void form.retry()}>
              Retry original action
            </IonButton>
          )}
        </form>
      )}
    </details>
  );
}
