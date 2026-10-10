import { IonButton } from '@ionic/react';
import { useState } from 'react';
import { useLocation } from 'react-router-dom';
import { useData, useSave } from '../lib/api';
import { Check, Errors, Loading, Select, Status, Submit } from '../components/ui';
import { format } from '../lib/money';
import type { PackageTerms } from './Billing';
export interface BillingGateway {
  id: string;
  currencies: string[];
}
interface BillingBusiness {
  id: string;
  name: string;
  business_type: string;
  package_enabled: boolean;
}
interface CheckoutPayment {
  reference: string;
  gateway: string;
  status: string;
  currency: string;
  amount_minor: string;
  checkout: { type: 'redirect' | 'form'; url: string; fields?: Record<string, string> } | null;
}
const names: Record<string, string> = {
  esewa: 'eSewa',
  khalti: 'Khalti',
  stripe: 'Stripe',
  paypal: 'PayPal',
};
const unresolved = (payment: CheckoutPayment) =>
  ['created', 'initiating', 'pending', 'unknown'].includes(payment.status);
function safeCheckout(payment: CheckoutPayment) {
  try {
    const url = new URL(payment.checkout!.url);
    const hosts: Record<string, string[]> = {
      esewa: ['rc-epay.esewa.com.np', 'epay.esewa.com.np'],
      khalti: ['test-pay.khalti.com', 'pay.khalti.com'],
      stripe: ['checkout.stripe.com'],
      paypal: ['www.sandbox.paypal.com', 'www.paypal.com'],
    };
    return (
      url.protocol === 'https:' &&
      !url.username &&
      !url.password &&
      !url.port &&
      hosts[payment.gateway]?.includes(url.hostname) &&
      (payment.gateway === 'esewa'
        ? payment.checkout?.type === 'form'
        : payment.checkout?.type === 'redirect')
    );
  } catch {
    return false;
  }
}
function PaymentCard({
  accountId,
  payment,
  callback,
  onChecked,
}: {
  accountId: string;
  payment: CheckoutPayment;
  callback?: string;
  onChecked: (payment: CheckoutPayment) => void;
}) {
  const verification = useSave();
  const [latest, setLatest] = useState<CheckoutPayment>();
  const current = latest || payment;
  return (
    <div className="panel section">
      <h4>{names[current.gateway] || current.gateway} payment</h4>
      <Status value={current.status} />
      <p>
        {current.currency} {format(current.amount_minor)}
      </p>
      <small>Reference: {current.reference}</small>
      <Errors error={verification.error} />
      {unresolved(current) && (
        <>
          <p>Package access changes after provider verification.</p>
          {current.checkout &&
            safeCheckout(current) &&
            (current.checkout.type === 'form' ? (
              <form method="POST" action={current.checkout.url}>
                {Object.entries(current.checkout.fields || {}).map(([name, value]) => (
                  <input key={name} type="hidden" name={name} value={value} />
                ))}
                <button className="button" type="submit" disabled={verification.busy}>
                  Continue with {names[current.gateway]}
                </button>
              </form>
            ) : (
              <a className="button" href={current.checkout.url}>
                Continue with {names[current.gateway]}
              </a>
            ))}
          <IonButton
            disabled={verification.busy}
            onClick={() =>
              void verification.save<CheckoutPayment>(
                `/api/billing/accounts/${accountId}/payments/${current.reference}/confirm`,
                callback ? { data: callback } : {},
                (data) => {
                  setLatest(data);
                  onChecked(data);
                },
              )
            }
          >
            Check payment status
          </IonButton>
          {current.status === 'unknown' && (
            <p>
              Outcome unconfirmed. Check status before starting another payment. Contact platform
              with reference if unresolved.
            </p>
          )}
        </>
      )}
    </div>
  );
}
function ReturnedPayment({
  accountId,
  reference,
  callback,
  onChecked,
}: {
  accountId: string;
  reference: string;
  callback?: string;
  onChecked: (payment: CheckoutPayment) => void;
}) {
  const payment = useData<{ data: CheckoutPayment }>(
    `/api/billing/accounts/${accountId}/payments/${reference}`,
  );
  return payment.loading || payment.error ? (
    <Loading error={payment.error} retry={payment.reload} />
  ) : (
    payment.data && (
      <PaymentCard
        accountId={accountId}
        payment={payment.data.data}
        callback={callback}
        onChecked={onChecked}
      />
    )
  );
}
export default function BillingCheckout({
  accountId,
  packages,
  gateways,
  onRenewed,
}: {
  accountId: string;
  packages: PackageTerms[];
  gateways: BillingGateway[];
  onRenewed: () => void;
}) {
  const businesses = useData<{ data: BillingBusiness[] }>(
    `/api/billing/accounts/${accountId}/businesses`,
  );
  const history = useData<{ data: CheckoutPayment[] }>(
    `/api/billing/accounts/${accountId}/payments`,
  );
  const form = useSave();
  const [planId, setPlanId] = useState('');
  const [currency, setCurrency] = useState('');
  const [gateway, setGateway] = useState('');
  const [retained, setRetained] = useState<string[]>();
  const [reviewed, setReviewed] = useState(false);
  const [attempt, setAttempt] = useState<CheckoutPayment>();
  const plans = packages.filter((plan) => plan.prices.length > 0);
  const plan = plans.find((item) => item.id === planId) || plans[0];
  const price = plan?.prices.find((item) => item.currency === currency) || plan?.prices[0];
  const providers = gateways.filter((item) => item.currencies.includes(price?.currency || ''));
  const provider = providers.find((item) => item.id === gateway) || providers[0];
  const selected = retained ?? businesses.data?.data.map((business) => business.id) ?? [];
  const pending = Boolean(attempt && unresolved(attempt)) || history.data?.data.some(unresolved);
  const blocked = form.busy || form.uncertain || pending;
  const location = useLocation();
  const query = new URLSearchParams(location.search);
  const returned =
    query.get('account') === accountId &&
    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(
      query.get('payment') || '',
    )
      ? query.get('payment')!
      : undefined;
  function checked(payment: CheckoutPayment) {
    if (attempt?.reference === payment.reference) setAttempt(payment);
    history.reload();
    if (payment.status === 'verified') onRenewed();
  }
  return (
    <div className="section">
      {returned && (
        <ReturnedPayment
          accountId={accountId}
          reference={returned}
          callback={query.get('data') || undefined}
          onChecked={checked}
        />
      )}
      <h3>Buy or renew package</h3>
      {businesses.loading || businesses.error || history.loading || history.error ? (
        <Loading
          error={businesses.error || history.error}
          retry={() => {
            businesses.reload();
            history.reload();
          }}
        />
      ) : (
        <>
          {!gateways.length && (
            <p>Online payment is not configured. Contact platform for payment setup.</p>
          )}
          {!plans.length ? (
            <p>Contact platform for pricing.</p>
          ) : (
            <form
              className="narrow-form"
              onSubmit={(event) => {
                event.preventDefault();
                if (
                  !plan ||
                  !price ||
                  !provider ||
                  !reviewed ||
                  blocked ||
                  selected.length > plan.max_businesses
                )
                  return;
                void form.save<CheckoutPayment>(
                  `/api/billing/accounts/${accountId}/checkout`,
                  {
                    package_id: plan.id,
                    expected_version: plan.version,
                    gateway: provider.id,
                    currency: price.currency,
                    retained_business_ids: selected,
                  },
                  (data) => {
                    setAttempt(data);
                    history.reload();
                  },
                );
              }}
            >
              <Errors error={form.error} />
              <fieldset disabled={blocked}>
                <Select
                  label="Package"
                  value={plan.id}
                  onChange={(value) => {
                    setPlanId(value);
                    setCurrency('');
                    setGateway('');
                    setReviewed(false);
                  }}
                >
                  {plans.map((item) => (
                    <option key={item.id} value={item.id}>
                      {item.name}
                    </option>
                  ))}
                </Select>
                <Select
                  label="Payment currency"
                  value={price?.currency || ''}
                  onChange={(value) => {
                    setCurrency(value);
                    setGateway('');
                    setReviewed(false);
                  }}
                >
                  {plan.prices.map((item) => (
                    <option key={item.currency}>{item.currency}</option>
                  ))}
                </Select>
                <Select
                  label="Payment provider"
                  value={provider?.id || ''}
                  onChange={(value) => {
                    setGateway(value);
                    setReviewed(false);
                  }}
                >
                  {providers.map((item) => (
                    <option key={item.id} value={item.id}>
                      {names[item.id]}
                    </option>
                  ))}
                </Select>
                <p>
                  <strong>
                    {price?.currency} {price && format(price.amount_minor)}
                  </strong>
                </p>
                <p>
                  {plan.duration_days} days · {plan.max_businesses} businesses ·{' '}
                  {plan.products.join(', ')}
                </p>
                <p>
                  Select businesses to retain package access. Excluded businesses keep
                  owner/accountant read and export access.
                </p>
                {businesses.data?.data.map((business) => (
                  <Check
                    key={business.id}
                    checked={selected.includes(business.id)}
                    onChange={(value) => {
                      setRetained(
                        value
                          ? [...selected, business.id]
                          : selected.filter((id) => id !== business.id),
                      );
                      setReviewed(false);
                    }}
                  >
                    {business.name}
                  </Check>
                ))}
                {selected.length > plan.max_businesses && (
                  <p role="alert">Select at most {plan.max_businesses} businesses.</p>
                )}
                <Check checked={reviewed} onChange={setReviewed}>
                  I reviewed package terms and price
                </Check>
                <Submit
                  busy={form.busy}
                  disabled={
                    !reviewed || !provider || selected.length > plan.max_businesses || blocked
                  }
                >
                  Create checkout
                </Submit>
              </fieldset>
              {form.uncertain && (
                <IonButton disabled={form.busy} onClick={() => void form.retry()}>
                  Retry original action
                </IonButton>
              )}
            </form>
          )}
          {pending && <p>Resolve previous payment before creating another checkout.</p>}
          <h3>Recent payments</h3>
          {attempt &&
            attempt.reference !== returned &&
            !history.data?.data.some((payment) => payment.reference === attempt.reference) && (
              <PaymentCard accountId={accountId} payment={attempt} onChecked={checked} />
            )}
          {history.data?.data
            .filter((payment) => payment.reference !== returned)
            .map((payment) => (
              <PaymentCard
                key={payment.reference}
                accountId={accountId}
                payment={payment}
                onChecked={checked}
              />
            ))}
          {!history.data?.data.length && !attempt && <p>No payments yet.</p>}
        </>
      )}
    </div>
  );
}
