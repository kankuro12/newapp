import { IonButton } from '@ionic/react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useWorkspace } from '../lib/context';
import { useData, useSave } from '../lib/api';
import { bsDisplay, currency } from '../lib/money';
import type { Item } from '../lib/types';
import { Check, Errors, Field, Heading, Loading, Select, Status, Submit } from '../components/ui';
import { ReviewedCheckout } from './Pos';

interface PlanSnapshot {
  name: string;
  fee_paisa: string;
  duration_days: number;
  visit_limit: number | null;
  class_access: boolean;
}
interface Membership {
  id: string;
  start_bs: number;
  end_bs: number;
  status: string;
  visits_used: number;
  version: number;
  document_id: string | null;
  document_number: string | null;
  document_cancelled: boolean;
  plan_snapshot: PlanSnapshot;
}
interface Member {
  id: string;
  name: string;
  phone: string | null;
  member_code: string;
  contact_id: string;
  current: Membership | null;
  memberships?: Membership[];
  checkins?: { id: string; business_date_bs: number }[];
}
interface Plan {
  id: string;
  name: string;
  item_id: string;
  item_name: string;
  fee_paisa: string;
  duration_days: number;
  visit_limit: number | null;
  class_access: boolean;
  active: boolean;
  version: number;
}
interface GymClass {
  id: string;
  name: string;
  trainer_name: string | null;
  start_minute: number;
  duration_minutes: number;
  capacity: number;
  booked: number;
}
interface Day {
  checkins: { id: string; name: string; member_code: string; minute: number }[];
  classes: GymClass[];
  expiring: { id: string; member_id: string; name: string; member_code: string; end_bs: number }[];
}
const time = (minute: number) =>
  `${String(Math.floor(minute / 60)).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`;
const nowMinute = () => new Date().getHours() * 60 + new Date().getMinutes();

function MemberSearch({ onPick }: { onPick: (member: Member) => void }) {
  const { base, revision, t } = useWorkspace();
  const [query, setQuery] = useState('');
  const members = useData<{ data: Member[] }>(
    `${base}/gym/members?q=${encodeURIComponent(query)}`,
    revision,
  );
  return (
    <>
      <Field
        label={t('Find member by name, phone or code')}
        value={query}
        onChange={(e) => setQuery(e.target.value)}
      />
      {members.loading || members.error ? (
        <Loading error={members.error} retry={members.reload} />
      ) : (
        <div className="panel">
          {members.data?.data.slice(0, 30).map((member) => (
            <button
              type="button"
              className="platform-row gym-member-row"
              key={member.id}
              onClick={() => onPick(member)}
            >
              <div>
                <strong>{member.name}</strong>
                <small>
                  {member.member_code}
                  {member.phone ? ` · ${member.phone}` : ''}
                </small>
              </div>
              {member.current ? (
                <span>
                  {member.current.plan_snapshot.name} · {t('until')}{' '}
                  {bsDisplay(member.current.end_bs)}
                </span>
              ) : (
                <Status value="expired" />
              )}
            </button>
          ))}
          {!members.data?.data.length && <p>{t('No members found.')}</p>}
        </div>
      )}
    </>
  );
}

function Today() {
  const { base, today, revision, changed, t } = useWorkspace();
  const day = useData<{ data: Day }>(`${base}/gym/day?date=${today}`, revision);
  const checkin = useSave();
  const [message, setMessage] = useState('');
  return (
    <>
      <section className="panel section">
        <h2>{t('Check in')}</h2>
        <Errors error={checkin.error} />
        {message && <p role="status">{message}</p>}
        <MemberSearch
          onPick={(member) =>
            void checkin.save(
              `${base}/gym/checkins`,
              { member_id: member.id, business_date_bs: today, minute: nowMinute() },
              () => {
                setMessage(`${member.name} ${t('checked in.')}`);
                changed();
              },
            )
          }
        />
      </section>
      {day.loading || day.error ? (
        <Loading error={day.error} retry={day.reload} />
      ) : (
        day.data && (
          <>
            <section className="panel section">
              <h2>
                {t('Checked in today')} ({day.data.data.checkins.length})
              </h2>
              {day.data.data.checkins.map((row) => (
                <div className="mini-row" key={row.id}>
                  <span>
                    {row.name}
                    <small>{row.member_code}</small>
                  </span>
                  <strong>{time(row.minute)}</strong>
                </div>
              ))}
            </section>
            <section className="panel section">
              <h2>{t('Memberships ending within 7 days')}</h2>
              {day.data.data.expiring.length ? (
                day.data.data.expiring.map((row) => (
                  <div className="mini-row" key={row.id}>
                    <span>
                      {row.name}
                      <small>{row.member_code}</small>
                    </span>
                    <strong>{bsDisplay(row.end_bs)}</strong>
                  </div>
                ))
              ) : (
                <p>{t('None.')}</p>
              )}
            </section>
            <Classes classes={day.data.data.classes} />
          </>
        )
      )}
    </>
  );
}

function Classes({ classes }: { classes: GymClass[] }) {
  const { base, today, business, changed, t } = useWorkspace();
  const manager = ['owner', 'manager'].includes(business.role);
  const form = useSave();
  const booking = useSave();
  const [name, setName] = useState('');
  const [trainer, setTrainer] = useState('');
  const [start, setStart] = useState('07:00');
  const [duration, setDuration] = useState('60');
  const [capacity, setCapacity] = useState('10');
  const [selected, setSelected] = useState<GymClass>();
  return (
    <section className="panel section">
      <h2>{t('Classes today')}</h2>
      <Errors error={booking.error} />
      {classes.map((row) => (
        <div className="platform-row" key={row.id}>
          <div>
            <strong>
              {time(row.start_minute)} · {row.name}
            </strong>
            <small>
              {row.trainer_name || t('No trainer')} · {row.booked}/{row.capacity} {t('booked')}
            </small>
          </div>
          <IonButton fill="outline" onClick={() => setSelected(row)}>
            {t('Book member')}
          </IonButton>
        </div>
      ))}
      {!classes.length && <p>{t('No classes scheduled today.')}</p>}
      {selected && (
        <div className="section">
          <h3>
            {t('Book')} {selected.name}
          </h3>
          <MemberSearch
            onPick={(member) =>
              void booking.save(
                `${base}/gym/classes/${selected.id}/bookings`,
                { member_id: member.id },
                () => {
                  setSelected(undefined);
                  changed();
                },
              )
            }
          />
        </div>
      )}
      {manager && (
        <form
          className="narrow-form section"
          onSubmit={(e) => {
            e.preventDefault();
            const [h, m] = start.split(':').map(Number);
            void form.save(
              `${base}/gym/classes`,
              {
                name,
                trainer_name: trainer || null,
                business_date_bs: today,
                start_minute: h * 60 + m,
                duration_minutes: Number(duration),
                capacity: Number(capacity),
              },
              () => {
                setName('');
                changed();
              },
            );
          }}
        >
          <h3>{t('Add class')}</h3>
          <Errors error={form.error} />
          <Field
            label={t('Class name')}
            value={name}
            required
            onChange={(e) => setName(e.target.value)}
          />
          <Field
            label={t('Trainer')}
            value={trainer}
            onChange={(e) => setTrainer(e.target.value)}
          />
          <Field
            label={t('Start time')}
            type="time"
            value={start}
            required
            onChange={(e) => setStart(e.target.value)}
          />
          <Field
            label={t('Minutes')}
            inputMode="numeric"
            value={duration}
            required
            onChange={(e) => setDuration(e.target.value)}
          />
          <Field
            label={t('Capacity')}
            inputMode="numeric"
            value={capacity}
            required
            onChange={(e) => setCapacity(e.target.value)}
          />
          <Submit busy={form.busy}>{t('Add class')}</Submit>
        </form>
      )}
    </section>
  );
}

function MemberDetail({ id, onClose }: { id: string; onClose: () => void }) {
  const { base, path, today, revision, changed, business, t } = useWorkspace();
  const member = useData<{ data: Member }>(`${base}/gym/members/${id}`, revision);
  const plans = useData<{ data: Plan[] }>(`${base}/gym/plans`);
  const join = useSave();
  const cancel = useSave();
  const [plan, setPlan] = useState('');
  const active = plans.data?.data.filter((row) => row.active) || [];
  const chosen = active.find((row) => row.id === plan);
  const manager = ['owner', 'manager'].includes(business.role);
  if (member.loading || member.error) return <Loading error={member.error} retry={member.reload} />;
  const data = member.data!.data;
  return (
    <section className="panel section">
      <IonButton fill="clear" onClick={onClose}>
        ← {t('All members')}
      </IonButton>
      <h2>{data.name}</h2>
      <p>
        {data.member_code}
        {data.phone ? ` · ${data.phone}` : ''}
      </p>
      {data.current ? (
        <p>
          <Status value="active" /> {data.current.plan_snapshot.name} ·{' '}
          {bsDisplay(data.current.start_bs)}–{bsDisplay(data.current.end_bs)}
          {data.current.plan_snapshot.visit_limit !== null &&
            ` · ${data.current.visits_used}/${data.current.plan_snapshot.visit_limit} ${t('visits')}`}
        </p>
      ) : (
        <p>{t('No active membership today.')}</p>
      )}
      <h3>{data.current ? t('Renew membership') : t('Join plan')}</h3>
      <Select label={t('Plan')} value={plan} onChange={setPlan}>
        <option value="">{t('Choose plan')}</option>
        {active.map((row) => (
          <option key={row.id} value={row.id}>
            {row.name} · {currency(row.fee_paisa)} · {row.duration_days} {t('days')}
          </option>
        ))}
      </Select>
      {!active.length && (
        <p>
          {t('No plans yet.')} {manager && t('Add one in the Plans tab.')}
        </p>
      )}
      {chosen && (
        <ReviewedCheckout
          endpoint={`${base}/gym/memberships`}
          context={{
            member_id: data.id,
            plan_id: chosen.id,
            plan_version: chosen.version,
            business_date_bs: today,
          }}
          busy={join.busy}
          disabled={false}
          uncertain={join.uncertain}
          retry={join.retry}
          error={join.error}
          onCheckout={(input) =>
            void join.save(`${base}/gym/memberships`, input, () => {
              setPlan('');
              changed();
            })
          }
        />
      )}
      <p>
        {t('Collect any remaining fee later from')}{' '}
        <Link to={`${path}/collections`}>{t('Collections / payments')}</Link>.
      </p>
      <h3>{t('Membership history')}</h3>
      <Errors error={cancel.error} />
      {data.memberships?.map((row) => (
        <div className="platform-row" key={row.id}>
          <div>
            <strong>
              {row.plan_snapshot.name} · {currency(row.plan_snapshot.fee_paisa)}
            </strong>
            <small>
              {bsDisplay(row.start_bs)}–{bsDisplay(row.end_bs)}
              {row.document_number && (
                <>
                  {' · '}
                  <Link to={`${path}/document/${row.document_id}`}>{row.document_number}</Link>
                </>
              )}
            </small>
          </div>
          <Status value={row.status} />
          {manager && row.status === 'active' && (
            <IonButton
              fill="clear"
              color="danger"
              title={t('Cancel or return the invoice first')}
              onClick={() =>
                void cancel.save(
                  `${base}/gym/memberships/${row.id}/cancel`,
                  { version: row.version },
                  changed,
                )
              }
            >
              {t('End membership')}
            </IonButton>
          )}
        </div>
      ))}
    </section>
  );
}

function Members() {
  const { base, today, changed, t } = useWorkspace();
  const [selected, setSelected] = useState<string>();
  const form = useSave();
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [code, setCode] = useState('');
  if (selected) return <MemberDetail id={selected} onClose={() => setSelected(undefined)} />;
  return (
    <>
      <form
        className="panel narrow-form section"
        onSubmit={(e) => {
          e.preventDefault();
          void form.save(
            `${base}/gym/members`,
            { name, phone: phone || null, member_code: code || null, business_date_bs: today },
            (member: Member) => {
              setName('');
              setPhone('');
              setCode('');
              changed();
              setSelected(member.id);
            },
          );
        }}
      >
        <h2>{t('Add member')}</h2>
        <Errors error={form.error} />
        <Field label={t('Name')} value={name} required onChange={(e) => setName(e.target.value)} />
        <Field
          label={t('Phone')}
          inputMode="tel"
          value={phone}
          onChange={(e) => setPhone(e.target.value)}
        />
        <Field
          label={t('Member code (optional)')}
          value={code}
          onChange={(e) => setCode(e.target.value)}
        />
        <Submit busy={form.busy}>{t('Add member')}</Submit>
      </form>
      <section className="section">
        <MemberSearch onPick={(member) => setSelected(member.id)} />
      </section>
    </>
  );
}

function Plans() {
  const { base, revision, changed, business, t } = useWorkspace();
  const plans = useData<{ data: Plan[] }>(`${base}/gym/plans`, revision);
  const items = useData<{ data: Item[] }>(`${base}/items?kind=service`);
  const form = useSave();
  const [editing, setEditing] = useState<Plan | null>();
  const [name, setName] = useState('');
  const [item, setItem] = useState('');
  const [fee, setFee] = useState('');
  const [days, setDays] = useState('30');
  const [visits, setVisits] = useState('');
  const [classes, setClasses] = useState(true);
  const [active, setActive] = useState(true);
  const manager = ['owner', 'manager'].includes(business.role);
  function edit(plan: Plan | null) {
    setEditing(plan);
    setName(plan?.name || '');
    setItem(plan?.item_id || '');
    setFee(plan ? (Number(plan.fee_paisa) / 100).toFixed(2) : '');
    setDays(String(plan?.duration_days ?? 30));
    setVisits(plan?.visit_limit ? String(plan.visit_limit) : '');
    setClasses(plan?.class_access ?? true);
    setActive(plan?.active ?? true);
  }
  const services = (items.data?.data || []).filter((row) => row.kind === 'service');
  return (
    <>
      {manager && <IonButton onClick={() => edit(null)}>{t('New plan')}</IonButton>}
      {editing !== undefined && (
        <form
          className="panel narrow-form section"
          onSubmit={(e) => {
            e.preventDefault();
            void form.save(
              editing ? `${base}/gym/plans/${editing.id}` : `${base}/gym/plans`,
              {
                version: editing?.version,
                name,
                item_id: item,
                fee,
                duration_days: Number(days),
                visit_limit: visits ? Number(visits) : null,
                class_access: classes,
                active,
              },
              () => {
                setEditing(undefined);
                changed();
              },
              editing ? 'PATCH' : 'POST',
            );
          }}
        >
          <h2>{editing ? t('Edit plan') : t('New plan')}</h2>
          <p>{t('Price changes apply to new joins and renewals only.')}</p>
          <Errors error={form.error} />
          <Field
            label={t('Plan name')}
            value={name}
            required
            onChange={(e) => setName(e.target.value)}
          />
          <Select label={t('Bill as service item')} value={item} onChange={setItem}>
            <option value="">{t('Choose service item')}</option>
            {services.map((row) => (
              <option key={row.id} value={row.id}>
                {row.name}
              </option>
            ))}
          </Select>
          {!services.length && (
            <p>{t('Create a service item first, for example “Membership fee”.')}</p>
          )}
          <Field
            label={t('Fee (NPR)')}
            inputMode="decimal"
            value={fee}
            required
            onChange={(e) => setFee(e.target.value)}
          />
          <Field
            label={t('Days')}
            inputMode="numeric"
            value={days}
            required
            onChange={(e) => setDays(e.target.value)}
          />
          <Field
            label={t('Visit limit (empty = unlimited)')}
            inputMode="numeric"
            value={visits}
            onChange={(e) => setVisits(e.target.value)}
          />
          <Check checked={classes} onChange={setClasses}>
            {t('Includes classes')}
          </Check>
          <Check checked={active} onChange={setActive}>
            {t('Available for new members')}
          </Check>
          <Submit busy={form.busy}>{t('Save plan')}</Submit>
        </form>
      )}
      {plans.loading || plans.error ? (
        <Loading error={plans.error} retry={plans.reload} />
      ) : (
        <section className="panel">
          {plans.data?.data.map((plan) => (
            <div className="platform-row" key={plan.id}>
              <div>
                <strong>{plan.name}</strong>
                <small>
                  {currency(plan.fee_paisa)} · {plan.duration_days} {t('days')}
                  {plan.visit_limit ? ` · ${plan.visit_limit} ${t('visits')}` : ''} ·{' '}
                  {plan.item_name}
                </small>
              </div>
              <Status value={plan.active ? 'active' : 'archived'} />
              {manager && (
                <IonButton fill="outline" onClick={() => edit(plan)}>
                  {t('Edit')}
                </IonButton>
              )}
            </div>
          ))}
          {!plans.data?.data.length && <p>{t('No plans yet.')}</p>}
        </section>
      )}
    </>
  );
}

export default function Gym() {
  const { t } = useWorkspace();
  const [tab, setTab] = useState<'today' | 'members' | 'plans'>('today');
  return (
    <div>
      <Heading
        eyebrow={t('GYM DESK')}
        title={t('Gym')}
        description={t('Check members in, sell or renew memberships and run classes.')}
      />
      <div className="segmented" role="tablist">
        {(
          [
            ['today', 'Today'],
            ['members', 'Members'],
            ['plans', 'Plans'],
          ] as const
        ).map(([value, label]) => (
          <IonButton
            key={value}
            role="tab"
            aria-selected={tab === value}
            fill={tab === value ? 'solid' : 'outline'}
            onClick={() => setTab(value)}
          >
            {t(label)}
          </IonButton>
        ))}
      </div>
      {tab === 'today' && <Today />}
      {tab === 'members' && <Members />}
      {tab === 'plans' && <Plans />}
    </div>
  );
}
