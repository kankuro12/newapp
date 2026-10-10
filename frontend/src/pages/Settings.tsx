import { IonButton } from '@ionic/react';
import { Link, useNavigate } from 'react-router-dom';
import { useState } from 'react';
import { useWorkspace } from '../lib/context';
import { send, useData, useSave } from '../lib/api';
import { amount, bsDisplay, currency, format, quantity } from '../lib/money';
import type { Account, Business, Category, Item, Lookup, Page, Party } from '../lib/types';
import Picker from '../components/Picker';
import { Check, Errors, Field, Heading, Loading, Select, Submit } from '../components/ui';
import { ReportTable } from './Reports';
import { Pagination } from './Records';

export function Opening() {
  const { base, path, today, business, changed, t } = useWorkspace();
  const lookup = useData<{ data: Lookup }>(`${base}/lookup`);
  const [step, setStep] = useState(1);
  const [date, setDate] = useState(bsDisplay(today));
  const [money, setMoney] = useState<Record<string, string>>({});
  const [stock, setStock] = useState<{ item: Item; qty: string; value: string; zero: boolean }[]>(
    [],
  );
  const [parties, setParties] = useState<{ party: Party; direction: string; value: string }[]>([]);
  const form = useSave();
  const navigate = useNavigate();
  const accounts = lookup.data?.data.accounts || [];
  let starting = 0n;
  let inputError: Error | undefined;
  try {
    Object.values(money).forEach((value) => {
      starting += amount(value || '0');
    });
    stock.forEach((row) => {
      quantity(row.qty);
      starting += amount(row.value);
    });
    parties.forEach((row) => {
      starting +=
        amount(row.value) *
        (['customer_owes', 'supplier_advance'].includes(row.direction) ? 1n : -1n);
    });
  } catch (error) {
    inputError = error as Error;
  }
  if (business.opening_finalized_at)
    return (
      <div className="panel">
        <h2>Starting balances finalized</h2>
        <p>
          {bsDisplay(business.opening_date_bs || '')} BS. Corrections use stock count or normal
          daily actions.
        </p>
        <Link to={path}>Go home</Link>
      </div>
    );
  if (!['owner', 'accountant'].includes(business.role))
    return <p>Starting setup requires owner or accountant.</p>;
  return (
    <>
      <Heading
        eyebrow="START WHERE YOUR BUSINESS IS TODAY"
        title={t('Starting balances')}
        description="Add current balances. No accounting grid, no duplicate stock value."
      />
      <div className="setup-steps">
        {['Cash & bank', 'Items in stock', 'Party dues', 'Review'].map((label, index) => (
          <button
            key={label}
            className={step === index + 1 ? 'active' : ''}
            onClick={() => setStep(index + 1)}
          >
            <span>{index + 1}</span>
            {label}
          </button>
        ))}
      </div>
      <form
        className="panel narrow-form"
        data-dirty="true"
        onSubmit={(e) => {
          e.preventDefault();
          if (step < 4) {
            setStep(step + 1);
            return;
          }
          const input = {
            business_date_bs: date,
            money: accounts
              .filter((a) => money[a.id] && amount(money[a.id]) > 0n)
              .map((a) => ({ account_id: a.id, amount: money[a.id] })),
            stock: stock.map((row) => ({
              item_id: row.item.id,
              qty: row.qty,
              value: row.value,
              zero_cost_confirmed: row.zero,
            })),
            parties: parties
              .filter((row) => amount(row.value) > 0n)
              .map((row) => ({
                contact_id: row.party.id,
                direction: row.direction,
                amount: row.value,
              })),
          };
          void form.save<Business>(`${base}/settings/opening-balances/finalize`, input, () => {
            changed();
            navigate(path);
          });
        }}
      >
        <Errors error={form.error || inputError || lookup.error} />
        <Field
          label="Starting date (BS)"
          value={date}
          onChange={(e) => setDate(e.target.value)}
          required
        />
        {step === 1 && (
          <>
            <h2>Cash & bank you have now</h2>
            <p className="subtle">Leave zero if starting fresh.</p>
            {accounts.map((a) => (
              <Field
                key={a.id}
                label={`${a.name} (NPR)`}
                inputMode="decimal"
                value={money[a.id] || '0'}
                onChange={(e) => setMoney((previous) => ({ ...previous, [a.id]: e.target.value }))}
              />
            ))}
          </>
        )}
        {step === 2 && (
          <>
            <h2>Items already in stock</h2>
            <Picker
              kind="items"
              stockOnly
              onPick={(row) => {
                const item = row as Item;
                if (item.kind === 'stock' && !stock.some((s) => s.item.id === item.id))
                  setStock((previous) => [
                    ...previous,
                    { item, qty: '1', value: '0', zero: false },
                  ]);
              }}
            />
            {stock.map((row, i) => (
              <div className="line-editor" key={row.item.id}>
                <h3>{row.item.name}</h3>
                <div className="form-grid">
                  <Field
                    label="Quantity"
                    inputMode="decimal"
                    value={row.qty}
                    onChange={(e) =>
                      setStock((previous) =>
                        previous.map((s, index) =>
                          index === i ? { ...s, qty: e.target.value } : s,
                        ),
                      )
                    }
                  />
                  <Field
                    label="Total starting value (NPR)"
                    inputMode="decimal"
                    value={row.value}
                    onChange={(e) =>
                      setStock((previous) =>
                        previous.map((s, index) =>
                          index === i ? { ...s, value: e.target.value } : s,
                        ),
                      )
                    }
                  />
                </div>
                <Check
                  checked={row.zero}
                  onChange={(value) =>
                    setStock((previous) =>
                      previous.map((s, index) => (index === i ? { ...s, zero: value } : s)),
                    )
                  }
                >
                  Explicit zero cost
                </Check>
                <IonButton
                  fill="clear"
                  color="danger"
                  onClick={() => setStock((previous) => previous.filter((_, index) => index !== i))}
                >
                  Remove
                </IonButton>
              </div>
            ))}
          </>
        )}
        {step === 3 && (
          <>
            <h2>Party dues</h2>
            <Picker
              kind="contacts"
              onPick={(row) => {
                const party = row as Party;
                setParties((previous) => [
                  ...previous,
                  {
                    party,
                    direction: party.is_customer ? 'customer_owes' : 'supplier_owed',
                    value: '0',
                  },
                ]);
              }}
            />
            {parties.map((row, i) => (
              <div className="line-editor" key={i}>
                <h3>{row.party.name}</h3>
                <Select
                  label="Who owes whom?"
                  value={row.direction}
                  onChange={(value) =>
                    setParties((previous) =>
                      previous.map((p, index) => (index === i ? { ...p, direction: value } : p)),
                    )
                  }
                >
                  {row.party.is_customer && (
                    <>
                      <option value="customer_owes">Customer owes business</option>
                      <option value="customer_credit">Business owes customer</option>
                    </>
                  )}
                  {(row.party.is_supplier || row.party.is_employee || row.party.is_rent) && (
                    <>
                      <option value="supplier_owed">Business owes party</option>
                      <option value="supplier_advance">Party owes business</option>
                    </>
                  )}
                </Select>
                <Field
                  label="Amount (NPR)"
                  inputMode="decimal"
                  value={row.value}
                  onChange={(e) =>
                    setParties((previous) =>
                      previous.map((p, index) =>
                        index === i ? { ...p, value: e.target.value } : p,
                      ),
                    )
                  }
                />
                <IonButton
                  fill="clear"
                  color="danger"
                  onClick={() =>
                    setParties((previous) => previous.filter((_, index) => index !== i))
                  }
                >
                  Remove
                </IonButton>
              </div>
            ))}
          </>
        )}
        {step === 4 && !inputError && (
          <>
            <h2>Review your starting point</h2>
            {accounts.map((a) => (
              <div className="mini-row" key={a.id}>
                <span>{a.name}</span>
                <strong>{currency(money[a.id] ? amount(money[a.id]) : 0n)}</strong>
              </div>
            ))}
            {stock.map((s) => (
              <div className="mini-row" key={s.item.id}>
                <span>
                  {s.item.name} · {s.qty} {s.item.unit_label}
                </span>
                <strong>{currency(amount(s.value))}</strong>
              </div>
            ))}
            {parties.map((p, i) => (
              <div className="mini-row" key={i}>
                <span>
                  {p.party.name} · {p.direction.replaceAll('_', ' ')}
                </span>
                <strong>{currency(amount(p.value))}</strong>
              </div>
            ))}
            <div className="grand-total">
              <span>Starting business value</span>
              <strong>{currency(starting)}</strong>
            </div>
            <p>Finalize once. Starting balances then remain immutable.</p>
          </>
        )}
        <div className="detail-actions">
          {step > 1 && (
            <IonButton fill="outline" onClick={() => setStep(step - 1)}>
              {t('Back')}
            </IonButton>
          )}
          <Submit busy={form.busy} disabled={!!inputError}>
            {step === 4 ? 'Confirm starting balances' : t('Continue')}
          </Submit>
        </div>
      </form>
    </>
  );
}

export function Settings() {
  const { path, business, t, changed } = useWorkspace();
  const [tab, setTab] = useState(business.role === 'owner' ? 'business' : 'lock');
  const allowed = business.role === 'owner';
  return (
    <>
      <Heading eyebrow="MAKE THIS BOOK YOURS" title={t('Settings')} />
      <div className="settings-tabs">
        {(allowed
          ? [
              ['business', 'Business'],
              ['staff', 'Staff'],
              ['accounts', 'Cash & bank'],
              ['categories', 'Expense categories'],
              ['lock', 'Lock period'],
              ['owner', 'Owner money history'],
            ]
          : [
              ['lock', 'Lock period'],
              ['owner', 'Owner money history'],
            ]
        ).map(([key, label]) => (
          <button className={tab === key ? 'active' : ''} key={key} onClick={() => setTab(key)}>
            {t(label)}
          </button>
        ))}
        <Link to={path + '/opening'}>{t('Starting balances')}</Link>
        {['owner', 'accountant'].includes(business.role) && (
          <Link to={path + '/audit'}>Audit history</Link>
        )}
      </div>
      {tab === 'business' && allowed ? (
        <BusinessSettings />
      ) : tab === 'staff' && allowed ? (
        <StaffSettings />
      ) : tab === 'accounts' && allowed ? (
        <AccountsSettings />
      ) : tab === 'categories' && allowed ? (
        <CategoriesSettings />
      ) : tab === 'lock' ? (
        <PeriodLock />
      ) : tab === 'owner' ? (
        <OwnerHistory />
      ) : (
        <p>Select available setting.</p>
      )}
      <IonButton fill="clear" onClick={changed}>
        Refresh business data
      </IonButton>
    </>
  );
}

function BusinessSettings() {
  const { base, business, changed, t } = useWorkspace();
  const [name, setName] = useState(business.name);
  const [address, setAddress] = useState(business.address || '');
  const [phone, setPhone] = useState(business.phone || '');
  const [pan, setPan] = useState(business.pan || '');
  const [locale, setLocale] = useState(business.default_locale || 'en');
  const [tax, setTax] = useState(!!business.tax_recording_enabled);
  const [rate, setRate] = useState(format(business.default_tax_bps || '0'));
  const form = useSave();
  const [saved, setSaved] = useState(false);
  return (
    <form
      className="panel narrow-form"
      onSubmit={(e) => {
        e.preventDefault();
        let bps: string;
        try {
          bps = amount(rate).toString();
        } catch {
          return;
        }
        void form.save(
          `${base}/settings/business`,
          {
            name,
            address,
            phone,
            pan,
            default_locale: locale,
            tax_recording_enabled: tax,
            default_tax_bps: bps,
          },
          () => {
            changed();
            setSaved(true);
          },
          'PATCH',
        );
      }}
    >
      <Errors error={form.error} />
      {saved && <p role="status">Business updated.</p>}
      <Field
        label="Business name"
        value={name}
        onChange={(e) => setName(e.target.value)}
        required
      />
      <Field label="Address" value={address} onChange={(e) => setAddress(e.target.value)} />
      <div className="form-grid">
        <Field label={t('Phone')} value={phone} onChange={(e) => setPhone(e.target.value)} />
        <Field label="PAN" value={pan} onChange={(e) => setPan(e.target.value)} />
      </div>
      <Select
        label="Business language"
        value={locale}
        onChange={(value) => setLocale(value as 'en' | 'ne')}
      >
        <option value="en">English</option>
        <option value="ne">नेपाली</option>
      </Select>
      <Check checked={tax} onChange={setTax}>
        Record bookkeeping tax
      </Check>
      {tax && (
        <Field
          label="Default tax rate (%)"
          inputMode="decimal"
          value={rate}
          onChange={(e) => setRate(e.target.value)}
        />
      )}
      <p className="subtle">Tax recording doesn’t enable statutory tax invoices.</p>
      <Submit busy={form.busy}>{t('Update business')}</Submit>
    </form>
  );
}
function StaffSettings() {
  const { base, revision, changed, t } = useWorkspace();
  type Member = { id: string; name: string; email: string; role: string; active: boolean };
  const data = useData<{
    data: {
      staff: Member[];
      invitations: {
        id: string;
        email: string;
        role: string;
        accepted_at: string | null;
        revoked_at: string | null;
      }[];
    };
  }>(`${base}/settings/staff`, revision);
  const [email, setEmail] = useState('');
  const [role, setRole] = useState('cashier');
  const [selected, setSelected] = useState<Member>();
  const [password, setPassword] = useState('');
  const form = useSave();
  const access = useSave();
  const [actionError, setActionError] = useState<Error>();
  return (
    <>
      <form
        className="panel narrow-form"
        onSubmit={(e) => {
          e.preventDefault();
          void form.save(`${base}/settings/staff/invitations`, { email, role }, () => {
            setEmail('');
            changed();
          });
        }}
      >
        <h2>Invite staff</h2>
        <Errors error={form.error || actionError} />
        <Field
          label={t('Email')}
          type="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          required
        />
        <Select label="Role" value={role} onChange={setRole}>
          {['cashier', 'manager', 'accountant', 'owner'].map((r) => (
            <option value={r} key={r}>
              {r}
            </option>
          ))}
        </Select>
        <Submit busy={form.busy}>Send invitation</Submit>
      </form>
      {selected && (
        <form
          className="panel narrow-form section"
          onSubmit={(e) => {
            e.preventDefault();
            void access.save(
              `${base}/settings/staff/${selected.id}`,
              { role: selected.role, active: selected.active, password },
              () => {
                setSelected(undefined);
                setPassword('');
                changed();
              },
              'PATCH',
            );
          }}
        >
          <h2>Access for {selected.name}</h2>
          <Errors error={access.error} />
          <Select
            label="Staff role"
            value={selected.role}
            onChange={(role) => setSelected({ ...selected, role })}
          >
            {['owner', 'manager', 'cashier', 'accountant'].map((r) => (
              <option key={r}>{r}</option>
            ))}
          </Select>
          <Check
            checked={selected.active}
            onChange={(active) => setSelected({ ...selected, active })}
          >
            Active access
          </Check>
          <Field
            label="Confirm your password"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />
          <Submit busy={access.busy}>Update access</Submit>
          <IonButton
            fill="clear"
            onClick={() => {
              setSelected(undefined);
              setPassword('');
              access.clear();
            }}
          >
            Cancel
          </IonButton>
        </form>
      )}
      <section className="panel section">
        <h2>{t('Staff')}</h2>
        {data.loading || data.error ? (
          <Loading error={data.error} retry={data.reload} />
        ) : (
          data.data?.data.staff.map((staff) => (
            <div className="mini-row" key={staff.id}>
              <div>
                <strong>{staff.name}</strong>
                <small>
                  {staff.email} · {staff.role} · {staff.active ? 'Active' : 'Revoked'}
                </small>
              </div>
              <IonButton
                fill="clear"
                onClick={() => {
                  setSelected({ ...staff, active: !!staff.active });
                  setPassword('');
                  access.clear();
                }}
              >
                Change access
              </IonButton>
            </div>
          ))
        )}
        <h3>Invitations</h3>
        {data.data?.data.invitations.map((invite) => (
          <div className="mini-row" key={invite.id}>
            <div>
              <strong>{invite.email}</strong>
              <small>
                {invite.role} ·{' '}
                {invite.accepted_at ? 'Accepted' : invite.revoked_at ? 'Revoked' : 'Pending'}
              </small>
            </div>
            {!invite.accepted_at && !invite.revoked_at && (
              <IonButton
                fill="clear"
                onClick={async () => {
                  try {
                    await send(`${base}/settings/staff/invitations/${invite.id}/revoke`, {});
                    changed();
                  } catch (e) {
                    setActionError(e as Error);
                  }
                }}
              >
                Revoke
              </IonButton>
            )}
          </div>
        ))}
      </section>
    </>
  );
}
function AccountsSettings() {
  const { base, revision, changed, t } = useWorkspace();
  const [page, setPage] = useState(1);
  const data = useData<Page<Account>>(`${base}/settings/accounts?page=${page}`, revision);
  const [name, setName] = useState('');
  const [kind, setKind] = useState('bank');
  const form = useSave();
  return (
    <>
      <form
        className="panel narrow-form"
        onSubmit={(e) => {
          e.preventDefault();
          void form.save(`${base}/settings/accounts`, { name, money_kind: kind }, () => {
            setName('');
            changed();
          });
        }}
      >
        <h2>Add cash / bank account</h2>
        <Errors error={form.error} />
        <Field
          label="Account name"
          value={name}
          onChange={(e) => setName(e.target.value)}
          required
        />
        <Select label="Kind" value={kind} onChange={setKind}>
          <option value="bank">Bank</option>
          <option value="cash">Cash</option>
        </Select>
        <Submit busy={form.busy}>{t('Save')}</Submit>
      </form>
      <section className="panel section">
        {(data.loading || data.error) && <Loading error={data.error} retry={data.reload} />}
        {data.data?.data.map((account) => (
          <div className="mini-row" key={account.id}>
            <strong>{account.name}</strong>
            <span>{currency(account.balance_paisa)}</span>
            {!account.system_key && (
              <IonButton
                fill="clear"
                onClick={() =>
                  void form.save(`${base}/settings/accounts/${account.id}/archive`, {}, changed)
                }
              >
                Archive
              </IonButton>
            )}
          </div>
        ))}
        {data.data && <Pagination page={page} pages={data.data.last_page} change={setPage} />}
      </section>
    </>
  );
}
function CategoriesSettings() {
  const { base, revision, changed } = useWorkspace();
  const [page, setPage] = useState(1);
  const data = useData<Page<Category>>(
    `${base}/settings/expense-categories?page=${page}`,
    revision,
  );
  const [name, setName] = useState('');
  const form = useSave();
  return (
    <>
      <form
        className="panel narrow-form"
        onSubmit={(e) => {
          e.preventDefault();
          void form.save(`${base}/settings/expense-categories`, { name }, () => {
            setName('');
            changed();
          });
        }}
      >
        <h2>Add expense category</h2>
        <Errors error={form.error} />
        <Field
          label="Category name"
          value={name}
          onChange={(e) => setName(e.target.value)}
          required
        />
        <Submit busy={form.busy}>Save category</Submit>
      </form>
      <section className="panel section">
        {(data.loading || data.error) && <Loading error={data.error} retry={data.reload} />}
        {data.data?.data.map((row) => (
          <div className="mini-row" key={row.id}>
            <strong>{row.name}</strong>
            <IonButton
              fill="clear"
              onClick={() =>
                void form.save(`${base}/settings/expense-categories/${row.id}/archive`, {}, changed)
              }
            >
              Archive
            </IonButton>
          </div>
        ))}
        {data.data && <Pagination page={page} pages={data.data.last_page} change={setPage} />}
      </section>
    </>
  );
}
function PeriodLock() {
  const { base, today, business, changed } = useWorkspace();
  const [date, setDate] = useState(bsDisplay(today));
  const [reason, setReason] = useState('');
  const [password, setPassword] = useState('');
  const form = useSave();
  if (!['owner', 'accountant'].includes(business.role))
    return <p>Period lock requires owner or accountant.</p>;
  return (
    <form
      className="panel narrow-form"
      onSubmit={(e) => {
        e.preventDefault();
        if (
          window.confirm('Lock all financial dates through ' + date + '? Reopening is unavailable.')
        )
          void form.save(
            `${base}/settings/close-through`,
            { business_date_bs: date, reason, password },
            () => {
              setPassword('');
              changed();
            },
          );
      }}
    >
      <h2>Lock completed period</h2>
      <p>Checks balances first. Entries on or before locked date cannot change.</p>
      {business.closed_through_bs && (
        <p>Currently locked through {bsDisplay(business.closed_through_bs)} BS</p>
      )}
      <Errors error={form.error} />
      <Field
        label="Close through (BS)"
        value={date}
        onChange={(e) => setDate(e.target.value)}
        required
      />
      <Field
        label="Reason"
        value={reason}
        onChange={(e) => setReason(e.target.value)}
        minLength={5}
        required
      />
      <Field
        label="Confirm your password"
        type="password"
        autoComplete="current-password"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        required
      />
      <Submit busy={form.busy}>Lock period</Submit>
    </form>
  );
}
function OwnerHistory() {
  const { base, revision, today, changed } = useWorkspace();
  const [page, setPage] = useState(1);
  const data = useData<
    Page<{
      id: string;
      owner_entry_kind: string;
      description: string;
      business_date_bs: number;
      amount_paisa: string;
      status: string;
    }>
  >(`${base}/owner-money?page=${page}`, revision);
  const form = useSave();
  return (
    <section className="panel">
      <h2>Owner money history</h2>
      <Errors error={form.error} />
      {(data.loading || data.error) && <Loading error={data.error} retry={data.reload} />}
      {data.data?.data.map((row) => (
        <div className="mini-row" key={row.id}>
          <div>
            <strong>{row.owner_entry_kind}</strong>
            <small>
              {row.description} · {bsDisplay(row.business_date_bs)}
            </small>
          </div>
          <strong>{currency(row.amount_paisa)}</strong>
          <span>{row.status}</span>
          {row.status === 'posted' && (
            <IonButton
              fill="clear"
              color="danger"
              onClick={() => {
                const reason = window.prompt('Cancellation reason (minimum 5 characters)');
                if (reason)
                  void form.save(
                    `${base}/owner-money/${row.id}/cancel`,
                    { reason, business_date_bs: today },
                    changed,
                  );
              }}
            >
              Cancel mistaken entry
            </IonButton>
          )}
        </div>
      ))}
      {data.data && <Pagination page={page} pages={data.data.last_page} change={setPage} />}
    </section>
  );
}
export function Audit() {
  const { base, revision } = useWorkspace();
  const [page, setPage] = useState(1);
  const data = useData<Page<Record<string, unknown>>>(`${base}/audit?page=${page}`, revision);
  return (
    <>
      <Heading title="Audit history" description="Append-only record of business actions." />
      {data.loading || data.error ? (
        <Loading error={data.error} retry={data.reload} />
      ) : (
        <>
          <ReportTable rows={data.data?.data || []} />
          {data.data && <Pagination page={page} pages={data.data.last_page} change={setPage} />}
        </>
      )}
    </>
  );
}
