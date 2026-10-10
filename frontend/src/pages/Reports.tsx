import { IonButton, IonIcon } from '@ionic/react';
import { downloadOutline, printOutline } from 'ionicons/icons';
import { useLocation } from 'react-router-dom';
import { useEffect, useState } from 'react';
import { useWorkspace } from '../lib/context';
import { useData } from '../lib/api';
import { bsDisplay, currency, format } from '../lib/money';
import type { Lookup, Overview, Party } from '../lib/types';
import Picker from '../components/Picker';
import { Errors, Field, Heading, Loading, Select } from '../components/ui';

const reports = [
  ['overview', 'Overview'],
  ['profit-loss', 'Profit & loss'],
  ['balance-sheet', 'Balance sheet'],
  ['receivables', 'Customers owe'],
  ['payables', 'Still to pay'],
  ['sales', 'Sales'],
  ['purchases', 'Purchases'],
  ['expenses', 'Expenses'],
  ['money', 'Money'],
  ['cashbook', 'Cash & bank book'],
  ['statement', 'Party statement'],
  ['stock', 'Stock valuation'],
  ['low-stock', 'Low stock'],
  ['stock-movements', 'Stock movements'],
  ['customer-aging', 'Customer aging'],
  ['supplier-aging', 'Payable aging'],
  ['reconciliation', 'Balance reconciliation'],
  ['trial-balance', 'Trial balance'],
];
export default function Reports() {
  const { base, today, revision, t, business } = useWorkspace();
  const params = new URLSearchParams(useLocation().search);
  const [report, setReport] = useState(params.get('report') || 'overview');
  const [view, setView] = useState(
    params.get('report') === 'statement' && !params.get('contact') ? 'filters' : 'results',
  );
  const [from, setFrom] = useState(bsDisplay(today).slice(0, 8) + '01');
  const [to, setTo] = useState(bsDisplay(today));
  const [party, setParty] = useState<Party>();
  const [contactId, setContactId] = useState(params.get('contact') || '');
  const [account, setAccount] = useState('');
  const lookup = useData<{ data: Lookup }>(base + '/lookup');
  const filter =
    'from=' +
    encodeURIComponent(from) +
    '&to=' +
    encodeURIComponent(to) +
    (contactId ? '&contact_id=' + contactId : '') +
    (account ? '&account_id=' + account : '');
  const endpoint = base + '/reports/' + report + '?' + filter;
  const { data, loading, error, reload } = useData<{
    data:
      | Overview
      | Record<string, unknown>[]
      | { rows: Record<string, unknown>[]; opening_paisa: string; closing_paisa: string };
  }>(report === 'statement' && !contactId ? null : endpoint, revision);
  const overview = data?.data as Overview;
  const rows = Array.isArray(data?.data)
    ? data.data
    : data?.data && 'rows' in data.data
      ? data.data.rows
      : undefined;
  const activeView = view === 'balances' && (!data || rows) ? 'results' : view;
  const sections = [
    ['results', 'Results'],
    ['filters', 'Filters'],
    ...(data && !rows ? [['balances', 'Balances']] : []),
  ];
  return (
    <div className="report-tools">
      <div className="no-print">
        <Heading title={t('Reports')}>
          <IonButton
            className="report-print"
            fill="outline"
            aria-label={t('Print')}
            title={t('Print')}
            onClick={() => window.print()}
          >
            <IonIcon icon={printOutline} />
          </IonButton>
          {['owner', 'accountant'].includes(business.role) && (
            <a className="export-link" href={base + '/reports/' + report + '/export?' + filter}>
              <IonIcon icon={downloadOutline} />
              {t('Download CSV')}
            </a>
          )}
        </Heading>
        <nav className="report-sections" aria-label={t('Report sections')}>
          {sections.map(([key, label]) => (
            <button
              key={key}
              type="button"
              aria-pressed={activeView === key}
              onClick={() => setView(key)}
            >
              {t(label)}
            </button>
          ))}
        </nav>
        <section
          hidden={activeView !== 'filters'}
          className="panel report-filter-panel"
          aria-label={t('Filters')}
        >
          <div className="report-filters">
            <Select
              label={t('Report')}
              value={report}
              onChange={(value) => {
                setReport(value);
                setAccount('');
              }}
            >
              {reports.map(([key, label]) => (
                <option value={key} key={key}>
                  {t(label)}
                </option>
              ))}
            </Select>
            <Field
              label={t('From') + ' (BS)'}
              value={from}
              onChange={(event) => setFrom(event.target.value)}
            />
            <Field
              label={t('To') + ' (BS)'}
              value={to}
              onChange={(event) => setTo(event.target.value)}
            />
            {['statement', 'cashbook'].includes(report) && (
              <Select label={t('Account')} value={account} onChange={setAccount}>
                {report === 'statement' ? (
                  <>
                    <option value="">{t('Customer channel')}</option>
                    <option value={lookup.data?.data.channels?.payables_id || ''}>
                      {t('Supplier channel')}
                    </option>
                  </>
                ) : (
                  lookup.data?.data.accounts.map((value) => (
                    <option value={value.id} key={value.id}>
                      {value.name}
                    </option>
                  ))
                )}
              </Select>
            )}
            {report === 'statement' && (
              <div className="report-party">
                {party && <strong>{party.name}</strong>}
                <Picker
                  kind="contacts"
                  onPick={(value) => {
                    setParty(value as Party);
                    setContactId(value.id);
                  }}
                />
              </div>
            )}
          </div>

          <Errors error={lookup.error} />
          <IonButton
            onClick={() => setView('results')}
            disabled={report === 'statement' && !contactId}
          >
            {t('Show results')}
          </IonButton>
        </section>
      </div>
      <section hidden={activeView !== 'results'} className="report-body" aria-label={t('Results')}>
        <h2>{t(reports.find(([key]) => key === report)?.[1] || report)}</h2>
        <p className="subtle">
          {from} — {to} BS · NPR
        </p>
        {error || loading ? (
          <Loading error={error} retry={reload} />
        ) : !data ? (
          <p>{t('Choose contact to view statement.')}</p>
        ) : rows ? (
          <>
            {!Array.isArray(data.data) && 'opening_paisa' in data.data && (
              <div className="metrics compact">
                <div className="metric panel">
                  <span>{t('Starting balance')}</span>
                  <strong>{currency(data.data.opening_paisa)}</strong>
                </div>
                <div className="metric panel">
                  <span>{t('Balance')}</span>
                  <strong>{currency(data.data.closing_paisa)}</strong>
                </div>
              </div>
            )}
            <ReportTable key={endpoint} rows={rows} t={t} />
          </>
        ) : (
          <div className="metrics">
            {(report === 'balance-sheet'
              ? [
                  ['Assets', overview.assets_paisa],
                  ['Liabilities', overview.liabilities_paisa],
                  ['Equity + retained profit', overview.equity_paisa],
                  [
                    'Balance check',
                    (
                      BigInt(overview.assets_paisa) -
                      BigInt(overview.liabilities_paisa) -
                      BigInt(overview.equity_paisa)
                    ).toString(),
                  ],
                ]
              : [
                  ['Net sales', overview.sales_paisa],
                  ['Cost of goods sold', overview.cogs_paisa],
                  ['Net profit', overview.profit_paisa],
                  ['Stock value', overview.inventory_paisa],
                ]
            ).map(([label, value]) => (
              <div className="panel metric" key={label}>
                <span>{t(label)}</span>
                <strong>{currency(value)}</strong>
              </div>
            ))}
          </div>
        )}
      </section>
      {data && !rows && (
        <section
          hidden={activeView !== 'balances'}
          className="panel report-balances"
          aria-label={t('Balances')}
        >
          <h2>{t('Balances')}</h2>
          {[
            ['Cash & bank', overview.cash_paisa],
            ['Customers owe', overview.receivables_paisa],
            ['Customer credits', overview.customer_credits_paisa],
            ['Still to pay', overview.payables_paisa],
            ['Supplier advances', overview.supplier_advances_paisa],
            ['Bank overdrafts', overview.bank_overdrafts_paisa],
          ].map(([label, value]) => (
            <div className="mini-row" key={label}>
              <span>{t(label)}</span>
              <strong>{currency(value)}</strong>
            </div>
          ))}
        </section>
      )}
    </div>
  );
}

export function ReportTable({
  rows,
  t = (value) => value,
}: {
  rows: Record<string, unknown>[];
  t?: (value: string) => string;
}) {
  const [page, setPage] = useState(1);
  const [mobile, setMobile] = useState(() => window.matchMedia('(max-width: 767px)').matches);
  useEffect(() => {
    const query = window.matchMedia('(max-width: 767px)');
    const update = () => setMobile(query.matches);
    query.addEventListener?.('change', update);
    return () => query.removeEventListener?.('change', update);
  }, []);
  if (!rows.length)
    return (
      <div className="panel">
        <p>{t('No entries in this range.')}</p>
      </div>
    );
  const size = mobile ? 2 : 6;
  const pages = Math.ceil(rows.length / size);
  const current = Math.min(page, pages);
  const ignored = [
    'tenant_id',
    'created_by',
    'source_id',
    'journal_id',
    'reversal_journal_id',
    'reversal_of_id',
    'account_id',
    'contact_id',
    'document_line_id',
    'stock_adjustment_id',
    'opening_balance_id',
    'created_at',
    'updated_at',
    'archived_at',
    'is_money',
    'normal_side',
    'system_key',
    'money_kind',
  ];
  const keys = Object.keys(rows[0]).filter((key) => !ignored.includes(key));
  const label = (key: string) =>
    t(
      ({ name: 'Name', due_paisa: 'Due', business_date_bs: 'Date (BS)' } as Record<string, string>)[
        key
      ] ||
        key
          .replace(/_(paisa|milli)$/, '')
          .replaceAll('_', ' ')
          .replace(/^received$/, 'Money in')
          .replace(/^paid$/, 'Money out'),
    );
  return (
    <div className="report-table">
      <div className="panel table-scroll">
        <table>
          <thead>
            <tr>
              {keys.map((key) => (
                <th key={key}>{label(key)}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row, index) => (
              <tr
                key={index}
                data-print-row
                hidden={index < (current - 1) * size || index >= current * size}
              >
                {keys.map((key) => (
                  <td key={key}>
                    {key.endsWith('_paisa')
                      ? currency(String(row[key] ?? 0))
                      : key.endsWith('_milli')
                        ? format(String(row[key] ?? 0), 3)
                        : key.endsWith('_bs')
                          ? bsDisplay(String(row[key] || ''))
                          : typeof row[key] === 'object'
                            ? JSON.stringify(row[key])
                            : String(row[key] ?? '')}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {pages > 1 && (
        <nav className="home-pager no-print" aria-label={t('Report pages')}>
          <button type="button" disabled={current <= 1} onClick={() => setPage(current - 1)}>
            {t('Previous rows')}
          </button>
          <span>
            {current} / {pages}
          </span>
          <button type="button" disabled={current >= pages} onClick={() => setPage(current + 1)}>
            {t('Next rows')}
          </button>
        </nav>
      )}
    </div>
  );
}
