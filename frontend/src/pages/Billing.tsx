import { IonContent, IonPage } from '@ionic/react';
import { Link } from 'react-router-dom';
import { useData } from '../lib/api';
import { Heading, Loading, Status } from '../components/ui';
import { format } from '../lib/money';
import BillingCheckout from './BillingCheckout';
import type { BillingGateway } from './BillingCheckout';
export interface PackageTerms {
  id: string;
  name: string;
  version: number;
  duration_days: number;
  trial_days: number;
  max_businesses: number;
  products: string[];
  prices: { currency: string; amount_minor: string }[];
  active?: boolean;
}
interface BillingAccount {
  id: string;
  name: string;
  role: string;
}
interface Entitlements {
  status: string;
  end_at: string | null;
  products: string[];
  max_businesses: number | null;
  package?: PackageTerms;
}
function AccountSubscription({
  account,
  packages,
  gateways,
}: {
  account: BillingAccount;
  packages: PackageTerms[];
  gateways: BillingGateway[];
}) {
  const subscription = useData<{ data: Entitlements }>(
    `/api/billing/accounts/${account.id}/subscription`,
  );
  return (
    <section className="panel section">
      <h2>{account.name}</h2>
      {subscription.loading || subscription.error ? (
        <Loading error={subscription.error} retry={subscription.reload} />
      ) : (
        subscription.data && (
          <>
            <Status value={subscription.data.data.status} />
            <h3>{subscription.data.data.package?.name || 'Existing business access'}</h3>
            {subscription.data.data.end_at && (
              <p>
                Access until:{' '}
                {new Date(subscription.data.data.end_at.replace(' ', 'T') + 'Z').toLocaleString(
                  'en-NP',
                  { timeZone: 'Asia/Kathmandu' },
                )}
              </p>
            )}
            <p>Business limit: {subscription.data.data.max_businesses ?? 'Existing terms'}</p>
            <p>Products: {subscription.data.data.products.join(', ')}</p>
          </>
        )
      )}
      {account.role === 'owner' && (
        <BillingCheckout
          accountId={account.id}
          packages={packages}
          gateways={gateways}
          onRenewed={subscription.reload}
        />
      )}
    </section>
  );
}
export default function Billing() {
  const accounts = useData<{ data: BillingAccount[] }>('/api/billing/accounts');
  const packages = useData<{ data: PackageTerms[] }>('/api/billing/packages');
  const gateways = useData<{ data: BillingGateway[] }>('/api/billing/gateways');
  return (
    <IonPage>
      <IonContent>
        <div className="businesses-wrap">
          <header className="businesses-header">
            <Link to="/businesses">Businesses</Link>
            <Link to="/account">My account</Link>
          </header>
          <Heading
            title="Accounts and packages"
            description="Manage package access for your businesses."
          />
          {accounts.loading || accounts.error ? (
            <Loading error={accounts.error} retry={accounts.reload} />
          ) : accounts.data?.data.length ? (
            accounts.data.data.map((account) => (
              <AccountSubscription
                key={account.id}
                account={account}
                packages={packages.data?.data || []}
                gateways={gateways.data?.data || []}
              />
            ))
          ) : (
            <p>Create your first business to start your account.</p>
          )}
          <h2>Available packages</h2>
          {packages.loading || packages.error ? (
            <Loading error={packages.error} retry={packages.reload} />
          ) : (
            packages.data?.data.map((plan) => (
              <section className="panel section" key={plan.id}>
                <h3>{plan.name}</h3>
                <p>
                  {plan.duration_days} days · {plan.max_businesses} businesses · {plan.trial_days}{' '}
                  trial days
                </p>
                <p>{plan.products.join(', ')}</p>
                {plan.prices.length ? (
                  plan.prices.map((price) => (
                    <p key={price.currency}>
                      {price.currency} {format(price.amount_minor)}
                    </p>
                  ))
                ) : (
                  <p>Contact platform for pricing.</p>
                )}
              </section>
            ))
          )}
        </div>
      </IonContent>
    </IonPage>
  );
}
