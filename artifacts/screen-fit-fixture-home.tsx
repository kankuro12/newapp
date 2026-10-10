import { IonButton, IonIcon } from '@ionic/react';
import {
  addOutline,
  arrowDownOutline,
  arrowForwardOutline,
  arrowUpOutline,
  cashOutline,
  cubeOutline,
  peopleOutline,
  receiptOutline,
  storefrontOutline,
  walletOutline,
} from 'ionicons/icons';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useState } from 'react';
import { useWorkspace } from '../lib/context';
import { useData } from '../screen-fit-data';
import { bsDisplay, currency, format } from '../lib/money';
import type { Dashboard as DashboardData } from '../lib/types';
import { Empty, Heading, Loading, Status } from '../components/ui';

export default function Dashboard() {
  const { base, path, business, revision, t, today } = useWorkspace();
  const navigate = useNavigate();
  const { data, error, loading, reload } = useData<{ data: DashboardData }>(base, revision);
  const [params, setParams] = useSearchParams();
  const [activityPage, setActivityPage] = useState(1);
  const [stockPage, setStockPage] = useState(1);
  const cashier = business.role === 'cashier';
  const sections = [
    ['actions', 'Daily actions'],
    ...(!cashier ? [['balances', 'Balances']] : []),
    ['activity', 'Recent activity'],
    ['stock', 'Low stock'],
  ];
  const selected = sections.find(([id]) => id === params.get('view')) || sections[0];
  if (loading || error) return <Loading error={error} retry={reload} />;
  const home = data!.data;
  const metrics = [
    ['Today’s sales', home.sales_paisa, receiptOutline, 'green', 'Sales after returns, before tax'],
    ['Cash & bank', home.cash_paisa, walletOutline, 'blue', 'Money available in your business'],
    [
      'Customers owe',
      home.receivables_paisa,
      peopleOutline,
      'orange',
      'Money you still need to collect',
    ],
    [
      'Still to pay',
      home.payables_paisa,
      storefrontOutline,
      'purple',
      'Supplier bills still outstanding',
    ],
  ];
  const actions = [
    ['New sale', '/documents/sale/new', receiptOutline, 'green'],
    ...(!cashier
      ? [
          ['New purchase', '/documents/purchase/new', storefrontOutline, 'blue'],
          ['Receive money', '/money/receipt/new', arrowDownOutline, 'orange'],
          ['Pay party', '/money/supplier_payment/new', arrowUpOutline, 'purple'],
        ]
      : []),
  ];
  const activityPages = Math.max(1, Math.ceil(home.recent.length / 2));
  const currentActivity = Math.min(activityPage, activityPages);
  const stockPages = Math.max(1, Math.ceil((home.low_stock?.length || 0) / 2));
  const currentStock = Math.min(stockPage, stockPages);
  return (
    <div className="home-tools">
      <Heading title={t('Home')} description={`${bsDisplay(today)} BS`}>
        <IonButton onClick={() => navigate(path + '/documents/sale/new')}>
          <IonIcon icon={addOutline} slot="start" />
          {t('New sale')}
        </IonButton>
      </Heading>
      {selected[0] === 'actions' && !business.opening_finalized_at && (
        <Link className="home-opening" to={path + '/opening'}>
          {t('Starting balances')}
          <IonIcon icon={arrowForwardOutline} />
        </Link>
      )}
      <nav className="home-sections" aria-label={t('Home sections')}>
        {sections.map(([id, label]) => (
          <button
            key={id}
            type="button"
            aria-pressed={selected[0] === id}
            onClick={() => setParams({ view: id }, { replace: true })}
          >
            {t(label)}
          </button>
        ))}
      </nav>
      <section role="region" aria-label={t(selected[1])}>
        {selected[0] === 'actions' && (
          <>
            <div className="quick-actions">
              {actions.map(([label, suffix, icon, color]) => (
                <Link className="quick-action panel" to={path + suffix} key={label}>
                  <span className={`icon-chip ${color}`}>
                    <IonIcon icon={icon} />
                  </span>
                  <span>{t(label)}</span>
                  <IonIcon icon={arrowForwardOutline} />
                </Link>
              ))}
            </div>
            {!cashier && (
              <Link className="home-expense" to={path + '/documents/expense/new'}>
                <IonIcon icon={walletOutline} />
                {t('Record expense')}
                <IonIcon icon={arrowForwardOutline} />
              </Link>
            )}
          </>
        )}
        {selected[0] === 'balances' && !cashier && (
          <div className="metrics">
            {metrics.map(([label, value, icon, color, caption]) => (
              <div className="metric panel" key={label}>
                <div className="metric-top">
                  <span>{t(label)}</span>
                  <span className={`icon-chip ${color}`}>
                    <IonIcon icon={icon} />
                  </span>
                </div>
                <strong>{currency(value)}</strong>
                <small>{t(caption)}</small>
              </div>
            ))}
          </div>
        )}
        {selected[0] === 'activity' && (
          <section className="panel activity-panel">
            <div className="section-title">
              <h2>{t('Recent activity')}</h2>
              <Link to={path + '/sales'}>
                {t('See all')}
                <IonIcon icon={arrowForwardOutline} />
              </Link>
            </div>
            {home.recent.length ? (
              <>
                <div className="activity-list">
                  {home.recent.slice((currentActivity - 1) * 2, currentActivity * 2).map((doc) => (
                    <Link key={doc.id} to={`${path}/document/${doc.id}`} className="activity-row">
                      <span
                        className={`icon-chip ${doc.type === 'sale' ? 'green' : doc.type === 'purchase' ? 'blue' : 'orange'}`}
                      >
                        <IonIcon
                          icon={
                            doc.type === 'sale'
                              ? receiptOutline
                              : doc.type === 'purchase'
                                ? storefrontOutline
                                : cashOutline
                          }
                        />
                      </span>
                      <div>
                        <strong>{doc.party_snapshot?.name || doc.number || 'Draft'}</strong>
                        <small>
                          {doc.number || 'Draft'} · {bsDisplay(doc.business_date_bs)}
                        </small>
                      </div>
                      <div className="activity-amount">
                        <strong>{currency(doc.total_paisa)}</strong>
                        <Status value={doc.status} />
                      </div>
                    </Link>
                  ))}
                </div>
                {activityPages > 1 && (
                  <HomePager
                    page={currentActivity}
                    pages={activityPages}
                    change={setActivityPage}
                    t={t}
                  />
                )}
              </>
            ) : (
              <Empty
                title={t('No entries yet')}
                action={t('New sale')}
                onClick={() => navigate(path + '/documents/sale/new')}
              />
            )}
          </section>
        )}
        {selected[0] === 'stock' && (
          <section className="panel stock-attention">
            <div className="section-title">
              <h2>{t('Low stock')}</h2>
              <span className="count-badge">{home.low_stock?.length || 0}</span>
            </div>
            {home.low_stock?.length ? (
              <>
                {home.low_stock.slice((currentStock - 1) * 2, currentStock * 2).map((item) => (
                  <Link key={item.id} to={path + '/items/' + item.id} className="stock-row">
                    <span className="icon-chip orange">
                      <IonIcon icon={cubeOutline} />
                    </span>
                    <div>
                      <strong>{item.name}</strong>
                      <small>
                        {format(item.qty_milli, 3)} {item.unit_label}
                      </small>
                    </div>
                  </Link>
                ))}
                {stockPages > 1 && (
                  <HomePager page={currentStock} pages={stockPages} change={setStockPage} t={t} />
                )}
              </>
            ) : (
              <div className="calm-empty">
                <IonIcon icon={cubeOutline} />
                <p>{t('No stock alerts.')}</p>
              </div>
            )}
          </section>
        )}
      </section>
    </div>
  );
}

function HomePager({
  page,
  pages,
  change,
  t,
}: {
  page: number;
  pages: number;
  change: (page: number) => void;
  t: (text: string) => string;
}) {
  return (
    <nav className="home-pager" aria-label={t('Pages')}>
      <button type="button" disabled={page <= 1} onClick={() => change(page - 1)}>
        {t('Previous')}
      </button>
      <span>
        {page} / {pages}
      </span>
      <button type="button" disabled={page >= pages} onClick={() => change(page + 1)}>
        {t('Next')}
      </button>
    </nav>
  );
}
