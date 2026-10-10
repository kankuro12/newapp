import { tenantSignOut } from '../lib/push';
import { IonContent, IonIcon, IonPage } from '@ionic/react';
import {
  barChartOutline,
  cashOutline,
  cubeOutline,
  gridOutline,
  homeOutline,
  peopleOutline,
  receiptOutline,
  settingsOutline,
  storefrontOutline,
  swapHorizontalOutline,
  walletOutline,
} from 'ionicons/icons';
import {
  Link,
  NavLink,
  Route,
  Routes,
  useLocation,
  useNavigate,
  useParams,
} from 'react-router-dom';
import { useState } from 'react';
import { Context } from '../lib/context';
import { request, resetSession, useData, useOnline } from '../lib/api';
import { translator } from '../lib/i18n';
import { bsDisplay } from '../lib/money';
import type { Business, User } from '../lib/types';
import { Empty, Loading } from '../components/ui';
import Dashboard from './Dashboard';
import Pos, { PosSetup } from './Pos';
import { WorkflowList, WorkflowDetail, WorkflowEditor } from './Workflows';
import FulfilmentDetail from './FulfilmentDetail';
import UserManual from './UserManual';
import More from './More';
import { DocumentList, DocumentDetail, MoneyList } from './Records';
import DocumentForm from './DocumentForm';
import { MasterList, MasterForm } from './Masters';
import { MoneyForm, ReturnForm, CountForm } from './DailyForms';
import Reports from './Reports';
import RegularPayments from './RegularPayments';
import { Collections, Followups, FollowupDetail, PartyTrading } from './PartyTools';
import { PriceLists, PriceListEditor } from './PriceLists';
import { BasketOffers, BasketOfferEditor } from './BasketOffers';
import { ItemCategories, Reorders } from './CatalogTools';
import Imports from './ImportTools';
import BarcodeTools from './BarcodeTools';
import LabelTools from './LabelTools';
import { Opening, Settings, Audit } from './Settings';

export default function Workspace({
  user,
  today,
  logout,
}: {
  user: User;
  today: number;
  logout: () => void;
}) {
  const { slug = '' } = useParams();
  const { data, error, loading, reload } = useData<{ data: Business[] }>(
    `/api/businesses?selection=${encodeURIComponent(slug)}`,
  );
  if (loading)
    return (
      <IonPage>
        <IonContent>
          <Loading />
        </IonContent>
      </IonPage>
    );
  const business = data?.data.find((b) => b.slug === slug);
  if (!business)
    return (
      <IonPage>
        <IonContent>
          <div className="center-screen">
            <Loading error={error || new Error('Business unavailable.')} retry={reload} />
            <Link to="/businesses">Choose another business</Link>
          </div>
        </IonContent>
      </IonPage>
    );
  return (
    <Shell
      key={slug}
      initial={business}
      businesses={data?.data || []}
      user={user}
      today={today}
      logout={logout}
    />
  );
}

function Shell({
  initial,
  businesses,
  user,
  today,
  logout,
}: {
  initial: Business;
  businesses: Business[];
  user: User;
  today: number;
  logout: () => void;
}) {
  const [business, setBusiness] = useState(initial);
  const [revision, setRevision] = useState(0);
  const [locale, setLocale] = useState<'en' | 'ne'>(() =>
    localStorage.getItem('bb-locale') === 'ne' ? 'ne' : initial.default_locale || user.locale,
  );
  const online = useOnline();
  const navigate = useNavigate();
  const location = useLocation();
  const t = translator(locale);
  const path = `/app/${business.slug}`;
  const base = `/api/app/${business.slug}`;
  const cashier = business.role === 'cashier';
  const expiry =
    business.access_status === 'trial' ? business.trial_ends_at : business.access_until;
  const expired =
    !['active', 'trial'].includes(business.access_status) ||
    !expiry ||
    Date.parse(expiry) <= Date.now();
  function changed() {
    setRevision((n) => n + 1);
    void request<{ data: Business[] }>('/api/businesses')
      .then((response) => {
        const current = response.data.find((b) => b.slug === business.slug);
        if (current) setBusiness(current);
      })
      .catch(() => undefined);
  }
  const links = [
    ['', 'Home', homeOutline],
    ['/pos', 'POS', storefrontOutline],
    ['/workflows', 'Quotes / orders / jobs', receiptOutline],
    ['/sales', 'Sales', receiptOutline],
    ['/contacts', 'Parties', peopleOutline],
    ['/items', 'Items', cubeOutline],
    ...(!cashier
      ? [
          ['/collections', 'Collections / payments', peopleOutline],
          ['/followups', 'Follow-ups', peopleOutline],
          ['/reorders', 'Reorder stock', storefrontOutline],
          ['/purchases', 'Purchases', storefrontOutline],
          ['/expenses', 'Expenses', walletOutline],
          ['/regular', 'Regular payments', peopleOutline],
          ['/money', 'Money', cashOutline],
          ['/reports', 'Reports', barChartOutline],
          ['/settings', 'Settings', settingsOutline],
        ]
      : []),
    ['/more', 'More', gridOutline],
  ];
  return (
    <Context.Provider value={{ business, base, path, today, revision, changed, t, locale }}>
      <IonPage>
        <IonContent>
          <div className="app-frame">
            <aside className="sidebar">
              <Link to={path} className="brand">
                <span className="brand-mark">b</span>
                <span>
                  business<span className="brand-book">book</span>
                </span>
              </Link>
              <label className="business-select">
                <span className="business-symbol">
                  <IonIcon icon={storefrontOutline} />
                </span>
                <select
                  aria-label={t('Switch business')}
                  value={business.slug}
                  onChange={(e) => {
                    if (
                      document.querySelector('[data-dirty="true"]') &&
                      !window.confirm('Unsaved or unconfirmed entry. Leave and switch business?')
                    )
                      return;
                    navigate(`/app/${e.target.value}`);
                  }}
                >
                  {businesses.map((b) => (
                    <option key={b.id} value={b.slug}>
                      {b.name}
                      {b.parent_tenant_id ? ' · Branch' : ''}
                    </option>
                  ))}
                </select>
              </label>
              <div className="sidebar-label">WORKSPACE</div>
              <nav>
                {links
                  .filter((link) => link[1] !== 'More')
                  .map(([suffix, label, icon]) => (
                    <NavLink
                      key={suffix}
                      to={path + suffix}
                      end={suffix === ''}
                      className={({ isActive }) => (isActive ? 'nav-link active' : 'nav-link')}
                    >
                      <IonIcon icon={icon} />
                      <span>{t(label)}</span>
                    </NavLink>
                  ))}
              </nav>
              <div className="sidebar-bottom">
                <div className="small-promo">
                  <span className="eyebrow">A LITTLE ORDER, EVERY DAY.</span>
                  <p>
                    Your next good decision
                    <br />
                    starts with a clear book.
                  </p>
                </div>
                <Link className="nav-link" to="/businesses">
                  <IonIcon icon={swapHorizontalOutline} />
                  {t('Switch business')}
                </Link>
              </div>
            </aside>
            <div className="main-wrap">
              <header className="topbar">
                <div className="topbar-business">
                  <span className="mobile-back-slot" />
                  <span className="mobile-logo brand-mark">b</span>
                  <strong>{business.name}</strong>
                  <span className="topbar-date">{bsDisplay(today)} BS</span>
                </div>
                <div className="topbar-actions">
                  <span className={`connection ${online ? '' : 'offline'}`}>
                    <i />
                    {online ? 'Online' : 'Offline'}
                  </span>
                  <button
                    className="locale-button"
                    onClick={() =>
                      setLocale((previous) => {
                        const next = previous === 'en' ? 'ne' : 'en';
                        localStorage.setItem('bb-locale', next);
                        return next;
                      })
                    }
                  >
                    {locale === 'en' ? 'नेपाली' : 'English'}
                  </button>
                  <button
                    className="avatar"
                    aria-label={t('Sign out')}
                    title={t('Sign out')}
                    onClick={async () => {
                      if (
                        document.querySelector('[data-dirty="true"]') &&
                        !window.confirm('Unsaved entry. Sign out?')
                      )
                        return;
                      await tenantSignOut(user.id);
                      resetSession();
                      logout();
                    }}
                  >
                    {user.name.slice(0, 1).toUpperCase()}
                  </button>
                </div>
              </header>
              {!online && (
                <div className="offline-banner" role="status">
                  Offline. Entries need connection; entered values remain on this screen.
                </div>
              )}
              {expired && (
                <div className="offline-banner">
                  Business access expired. Reading and export remain available.
                </div>
              )}
              <main
                className="page-wrap"
                key={location.pathname.includes('/new') ? location.pathname : business.slug}
              >
                <Routes>
                  <Route index element={<Dashboard />} />
                  <Route path="pos" element={<Pos />} />
                  <Route path="pos/setup" element={<PosSetup />} />
                  <Route path="workflows" element={<WorkflowList />} />
                  <Route path="workflows/new/:kind" element={<WorkflowEditor />} />
                  <Route
                    path="fulfilment/:id"
                    element={<FulfilmentDetail key={location.pathname} />}
                  />
                  <Route path="workflow/:id" element={<WorkflowDetail />} />
                  <Route path="sales" element={<DocumentList type="sale" />} />
                  <Route path="purchases" element={<DocumentList type="purchase" />} />
                  <Route path="expenses" element={<DocumentList type="expense" />} />
                  <Route path="collections" element={<Collections />} />
                  <Route path="followups" element={<Followups key={location.search} />} />
                  <Route path="followup/:id" element={<FollowupDetail key={location.pathname} />} />
                  <Route
                    path="contacts/:id/trading"
                    element={<PartyTrading key={location.pathname} />}
                  />
                  <Route path="regular" element={<RegularPayments />} />
                  <Route path="documents/:type/new" element={<DocumentForm />} />
                  <Route path="document/:id" element={<DocumentDetail />} />
                  <Route path="document/:id/return" element={<ReturnForm />} />
                  <Route path="contacts" element={<MasterList resource="contacts" />} />
                  <Route path="contacts/new" element={<MasterForm resource="contacts" />} />
                  <Route path="contacts/:id" element={<MasterForm resource="contacts" />} />
                  <Route path="item-categories" element={<ItemCategories />} />
                  <Route path="reorders" element={<Reorders />} />
                  <Route path="imports" element={<Imports />} />
                  <Route path="barcodes" element={<BarcodeTools />} />
                  <Route path="labels" element={<LabelTools />} />
                  <Route path="basket-offers" element={<BasketOffers />} />
                  <Route
                    path="basket-offers/new"
                    element={<BasketOfferEditor key={location.pathname} />}
                  />
                  <Route
                    path="basket-offers/:id"
                    element={<BasketOfferEditor key={location.pathname} />}
                  />
                  <Route path="price-lists" element={<PriceLists />} />
                  <Route path="price-lists/new" element={<PriceListEditor />} />
                  <Route path="price-lists/:id" element={<PriceListEditor />} />
                  <Route path="items" element={<MasterList resource="items" />} />
                  <Route path="items/new" element={<MasterForm resource="items" />} />
                  <Route path="items/:id" element={<MasterForm resource="items" />} />
                  <Route path="money" element={<MoneyList />} />
                  <Route path="money/:kind/new" element={<MoneyForm />} />
                  <Route path="stock/count" element={<CountForm />} />
                  <Route path="reports" element={<Reports />} />
                  <Route path="opening" element={<Opening />} />
                  <Route path="settings/*" element={<Settings />} />
                  <Route path="audit" element={<Audit />} />
                  <Route path="more" element={<More />} />
                  <Route path="help" element={<UserManual />} />
                  <Route
                    path="*"
                    element={
                      <Empty
                        title="Page unavailable"
                        action="Home"
                        onClick={() => navigate(path)}
                      />
                    }
                  />
                </Routes>
              </main>
              <footer className="app-footer">
                <span>Business Book · simple bookkeeping</span>
                <span>NPR · {business.role}</span>
              </footer>
            </div>
            <nav className="bottom-nav">
              {[
                ['', 'Home', homeOutline],
                ['/pos', 'POS', storefrontOutline],
                ['/sales', 'Sales', receiptOutline],
                ['/contacts', 'Parties', peopleOutline],
                ['/more', 'More', gridOutline],
              ].map(([suffix, label, icon]) => (
                <NavLink
                  key={suffix}
                  end={suffix === ''}
                  to={path + suffix}
                  className={({ isActive }) => (isActive ? 'active' : '')}
                >
                  <IonIcon icon={icon} />
                  <span>{t(label)}</span>
                </NavLink>
              ))}
            </nav>
          </div>
        </IonContent>
      </IonPage>
    </Context.Provider>
  );
}
