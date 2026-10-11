import { IonButton } from '@ionic/react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { request, send, useData } from '../lib/api';
import type { Page } from '../lib/types';
import { Check, Errors, Field, Heading, Loading, Select, Status, Submit } from '../components/ui';
import { format } from '../lib/money';
import { Pagination } from './Records';

interface AccountRow {
  id: string;
  name: string;
  status: string;
  version: string;
  package_status: string;
  package: string | null;
  end_at: string | null;
  owner_email: string | null;
  business_count: string;
}
interface PaymentRow {
  id: string;
  reference: string;
  gateway: string;
  currency: string;
  amount_minor: string;
  status: string;
  account_name: string;
  provider_transaction_id: string | null;
  issue: string | null;
  created_at: string;
}
interface Campaign {
  id: string;
  name: string;
  body: string;
  channels: string[];
  audience: string;
  status: string;
  scheduled_at: string | null;
  recipient_count: string;
  version: string;
  deliveries: Record<string, string>;
}
interface DeliveryRow {
  id: string;
  event: string;
  channel: string;
  status: string;
  error_code: string | null;
  attempts: string;
  created_at: string;
}
interface Preview {
  recipients: string;
  consented: Record<string, string>;
  message: { subject: string; lines: string[] };
}
const tabs = ['accounts', 'payments', 'campaigns', 'deliveries'] as const;
const nepal = (value: string | null) =>
  value
    ? new Date(value.replace(' ', 'T') + (value.includes('T') ? '' : 'Z')).toLocaleString('en-NP', {
        timeZone: 'Asia/Kathmandu',
      })
    : '—';

/** Password and reason confirmation shared by every sensitive platform action. */
function Confirm({
  title,
  children,
  submit,
  onDone,
}: {
  title: string;
  children?: ReactNode;
  submit: (secret: { password: string; reason: string }) => Promise<unknown>;
  onDone: () => void;
}) {
  const [password, setPassword] = useState('');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<Error>();
  async function run(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(undefined);
    try {
      await submit({ password, reason });
      setPassword('');
      onDone();
    } catch (failure) {
      setError(failure as Error);
    } finally {
      setBusy(false);
    }
  }
  return (
    <form className="panel narrow-form section" onSubmit={run}>
      <h3>{title}</h3>
      <Errors error={error} />
      {children}
      <Field
        label="Reason"
        value={reason}
        minLength={5}
        required
        onChange={(e) => setReason(e.target.value)}
      />
      <Field
        label="Confirm admin password"
        type="password"
        autoComplete="current-password"
        value={password}
        required
        onChange={(e) => setPassword(e.target.value)}
      />
      <Submit busy={busy}>Confirm</Submit>
    </form>
  );
}

function Accounts() {
  const [page, setPage] = useState(1);
  const [query, setQuery] = useState('');
  const [revision, setRevision] = useState(0);
  const rows = useData<Page<AccountRow>>(
    `/api/platform/accounts?page=${page}&q=${encodeURIComponent(query)}`,
    revision,
  );
  const [selected, setSelected] = useState<AccountRow>();
  return (
    <>
      <Field
        label="Search accounts"
        value={query}
        onChange={(e) => {
          setPage(1);
          setQuery(e.target.value);
        }}
      />
      <section className="panel">
        {rows.loading || rows.error ? (
          <Loading error={rows.error} retry={rows.reload} />
        ) : (
          rows.data?.data.map((row) => (
            <div className="platform-row" key={row.id}>
              <div>
                <strong>{row.name}</strong>
                <small>{row.owner_email}</small>
                <small>
                  {row.business_count} businesses · {row.package || 'No package'} · until{' '}
                  {nepal(row.end_at)}
                </small>
              </div>
              <Status value={row.status === 'suspended' ? 'suspended' : row.package_status} />
              <IonButton fill="outline" onClick={() => setSelected(row)}>
                {row.status === 'suspended' ? 'Restore' : 'Suspend'}
              </IonButton>
            </div>
          ))
        )}
        {rows.data && <Pagination page={page} pages={rows.data.last_page} change={setPage} />}
      </section>
      {selected && (
        <Confirm
          title={`${selected.status === 'suspended' ? 'Restore' : 'Suspend'} ${selected.name}`}
          submit={(secret) =>
            send(
              `/api/platform/accounts/${selected.id}/status`,
              {
                ...secret,
                status: selected.status === 'suspended' ? 'active' : 'suspended',
                version: Number(selected.version),
              },
              'PATCH',
            )
          }
          onDone={() => {
            setSelected(undefined);
            setRevision((n) => n + 1);
          }}
        />
      )}
    </>
  );
}

function Payments() {
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [revision, setRevision] = useState(0);
  const rows = useData<Page<PaymentRow>>(
    `/api/platform/payments?page=${page}&status=${status}`,
    revision,
  );
  const [selected, setSelected] = useState<PaymentRow>();
  const [outcome, setOutcome] = useState('refunded');
  return (
    <>
      <Select label="Payment status" value={status} onChange={setStatus}>
        {[
          '',
          'pending',
          'unknown',
          'verified',
          'abandoned',
          'expired',
          'failed',
          'refunded',
          'disputed',
        ].map((value) => (
          <option key={value} value={value}>
            {value || 'All'}
          </option>
        ))}
      </Select>
      <section className="panel">
        {rows.loading || rows.error ? (
          <Loading error={rows.error} retry={rows.reload} />
        ) : (
          rows.data?.data.map((row) => (
            <div className="platform-row" key={row.id}>
              <div>
                <strong>
                  {row.account_name} · {row.currency} {format(row.amount_minor)}
                </strong>
                <small>
                  {row.gateway} · {row.reference}
                </small>
                <small>
                  {nepal(row.created_at)}
                  {row.issue ? ` · ${row.issue}` : ''}
                </small>
              </div>
              <Status value={row.status} />
              {row.status === 'verified' && (
                <IonButton fill="outline" onClick={() => setSelected(row)}>
                  Refund or dispute
                </IonButton>
              )}
            </div>
          ))
        )}
        {rows.data && <Pagination page={page} pages={rows.data.last_page} change={setPage} />}
      </section>
      {selected && (
        <Confirm
          title={`Revoke package time for ${selected.reference}`}
          submit={(secret) =>
            send(`/api/platform/payments/${selected.id}/revoke`, { ...secret, status: outcome })
          }
          onDone={() => {
            setSelected(undefined);
            setRevision((n) => n + 1);
          }}
        >
          <p>
            Record the provider refund or dispute here. The purchased term stops; business books are
            not changed.
          </p>
          <Select label="Outcome" value={outcome} onChange={setOutcome}>
            <option value="refunded">Refunded</option>
            <option value="disputed">Disputed</option>
          </Select>
        </Confirm>
      )}
    </>
  );
}

function Campaigns() {
  const [revision, setRevision] = useState(0);
  const list = useData<{ data: Campaign[] }>('/api/platform/campaigns', revision);
  const [editing, setEditing] = useState<Campaign | null>();
  const [name, setName] = useState('');
  const [body, setBody] = useState('');
  const [channels, setChannels] = useState<string[]>(['email']);
  const [audience, setAudience] = useState('all');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<Error>();
  const [preview, setPreview] = useState<{ campaign: Campaign; data: Preview }>();
  const [action, setAction] = useState<{ campaign: Campaign; kind: 'schedule' | 'cancel' }>();
  const [when, setWhen] = useState('');
  const refresh = () => setRevision((n) => n + 1);
  function edit(campaign: Campaign | null) {
    setEditing(campaign);
    setName(campaign?.name || '');
    setBody(campaign?.body || '');
    setChannels(campaign?.channels || ['email']);
    setAudience(campaign?.audience || 'all');
    setError(undefined);
  }
  async function save(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(undefined);
    try {
      const input = { name, body, channels, audience, version: Number(editing?.version || 1) };
      if (editing) await send(`/api/platform/campaigns/${editing.id}`, input, 'PATCH');
      else await send('/api/platform/campaigns', input);
      setEditing(undefined);
      refresh();
    } catch (failure) {
      setError(failure as Error);
    } finally {
      setBusy(false);
    }
  }
  return (
    <>
      <IonButton onClick={() => edit(null)}>New campaign</IonButton>
      {editing !== undefined && (
        <form className="panel narrow-form section" onSubmit={save}>
          <h3>{editing ? 'Edit draft campaign' : 'New campaign'}</h3>
          <Errors error={error} />
          <Field label="Name" value={name} required onChange={(e) => setName(e.target.value)} />
          <Field label="Message">
            <textarea
              value={body}
              required
              minLength={5}
              maxLength={2000}
              rows={5}
              onChange={(e) => setBody(e.target.value)}
            />
          </Field>
          <Select label="Audience" value={audience} onChange={setAudience}>
            <option value="all">All account owners</option>
            <option value="trial">Owners on trial</option>
            <option value="active">Owners with paid packages</option>
            <option value="expired">Owners with expired packages</option>
          </Select>
          {['email', 'fcm', 'whatsapp'].map((channel) => (
            <Check
              key={channel}
              checked={channels.includes(channel)}
              onChange={(on) =>
                setChannels((current) =>
                  on ? [...current, channel] : current.filter((value) => value !== channel),
                )
              }
            >
              {channel === 'fcm' ? 'Push notification' : channel === 'email' ? 'Email' : 'WhatsApp'}
            </Check>
          ))}
          <p>Only owners who opted in to promotions on a channel receive it there.</p>
          <Submit busy={busy}>Save draft</Submit>
          <IonButton fill="clear" onClick={() => setEditing(undefined)}>
            Close
          </IonButton>
        </form>
      )}
      <section className="panel">
        {list.loading || list.error ? (
          <Loading error={list.error} retry={list.reload} />
        ) : list.data?.data.length ? (
          list.data.data.map((campaign) => (
            <div className="platform-row" key={campaign.id}>
              <div>
                <strong>{campaign.name}</strong>
                <small>
                  {campaign.audience} · {campaign.channels.join(', ')}
                </small>
                <small>
                  {campaign.scheduled_at ? `Scheduled ${nepal(campaign.scheduled_at)}` : 'Draft'}
                  {Object.keys(campaign.deliveries).length
                    ? ' · ' +
                      Object.entries(campaign.deliveries)
                        .map(([status, total]) => `${total} ${status}`)
                        .join(', ')
                    : ''}
                </small>
              </div>
              <Status value={campaign.status} />
              {campaign.status === 'draft' && (
                <>
                  <IonButton fill="outline" onClick={() => edit(campaign)}>
                    Edit
                  </IonButton>
                  <IonButton
                    fill="outline"
                    onClick={async () => {
                      try {
                        const result = await request<{ data: Preview }>(
                          `/api/platform/campaigns/${campaign.id}/preview`,
                        );
                        setPreview({ campaign, data: result.data });
                      } catch (failure) {
                        setError(failure as Error);
                      }
                    }}
                  >
                    Preview and send
                  </IonButton>
                </>
              )}
              {['draft', 'scheduled', 'sending'].includes(campaign.status) && (
                <IonButton
                  fill="clear"
                  color="danger"
                  onClick={() => setAction({ campaign, kind: 'cancel' })}
                >
                  Cancel
                </IonButton>
              )}
            </div>
          ))
        ) : (
          <p>No campaigns yet.</p>
        )}
      </section>
      {preview && (
        <section className="panel section">
          <h3>{preview.data.message.subject}</h3>
          {preview.data.message.lines.map((line) => (
            <p key={line}>{line}</p>
          ))}
          <p>
            {preview.data.recipients} owners in audience · opted in: email{' '}
            {preview.data.consented.email}, push {preview.data.consented.fcm}, WhatsApp{' '}
            {preview.data.consented.whatsapp}
          </p>
          <IonButton
            onClick={() => {
              setAction({ campaign: preview.campaign, kind: 'schedule' });
              setPreview(undefined);
            }}
          >
            Send or schedule
          </IonButton>
          <IonButton fill="clear" onClick={() => setPreview(undefined)}>
            Close
          </IonButton>
        </section>
      )}
      {action && (
        <Confirm
          title={
            action.kind === 'schedule'
              ? `Send ${action.campaign.name}`
              : `Cancel ${action.campaign.name}`
          }
          submit={(secret) =>
            action.kind === 'schedule'
              ? send(`/api/platform/campaigns/${action.campaign.id}/schedule`, {
                  ...secret,
                  version: Number(action.campaign.version),
                  scheduled_at: when ? new Date(when).toISOString() : null,
                })
              : send(`/api/platform/campaigns/${action.campaign.id}/cancel`, secret)
          }
          onDone={() => {
            setAction(undefined);
            setWhen('');
            refresh();
          }}
        >
          {action.kind === 'schedule' && (
            <Field
              label="Send later (local time, leave empty to send now)"
              type="datetime-local"
              value={when}
              onChange={(e) => setWhen(e.target.value)}
            />
          )}
        </Confirm>
      )}
    </>
  );
}

function Deliveries() {
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const rows = useData<Page<DeliveryRow>>(`/api/platform/deliveries?page=${page}&status=${status}`);
  return (
    <>
      <Select label="Delivery status" value={status} onChange={setStatus}>
        {['', 'pending', 'sending', 'sent', 'skipped', 'failed', 'unknown'].map((value) => (
          <option key={value} value={value}>
            {value || 'All'}
          </option>
        ))}
      </Select>
      <section className="panel">
        {rows.loading || rows.error ? (
          <Loading error={rows.error} retry={rows.reload} />
        ) : (
          rows.data?.data.map((row) => (
            <div className="platform-row" key={row.id}>
              <div>
                <strong>
                  {row.event} · {row.channel}
                </strong>
                <small>
                  {nepal(row.created_at)} · attempts {row.attempts}
                  {row.error_code ? ` · ${row.error_code}` : ''}
                </small>
              </div>
              <Status value={row.status} />
            </div>
          ))
        )}
        {rows.data && <Pagination page={page} pages={rows.data.last_page} change={setPage} />}
      </section>
    </>
  );
}

export default function PlatformBilling() {
  const [tab, setTab] = useState<(typeof tabs)[number]>('accounts');
  return (
    <section className="section">
      <Heading
        eyebrow="PLATFORM ADMINISTRATION"
        title="Accounts, payments and messages"
        description="Account access, payment outcomes, marketing campaigns and delivery diagnostics."
      />
      <div className="segmented" role="tablist">
        {tabs.map((value) => (
          <IonButton
            key={value}
            role="tab"
            aria-selected={tab === value}
            fill={tab === value ? 'solid' : 'outline'}
            onClick={() => setTab(value)}
          >
            {value[0].toUpperCase() + value.slice(1)}
          </IonButton>
        ))}
      </div>
      {tab === 'accounts' && <Accounts />}
      {tab === 'payments' && <Payments />}
      {tab === 'campaigns' && <Campaigns />}
      {tab === 'deliveries' && <Deliveries />}
    </section>
  );
}
