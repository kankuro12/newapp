import { IonButton, IonContent, IonIcon, IonPage } from '@ionic/react';
import { addOutline, arrowForwardOutline, storefrontOutline } from 'ionicons/icons';
import { Link, useNavigate } from 'react-router-dom';
import { useState } from 'react';
import { useData, request, resetSession, send, useSave } from '../lib/screen-fit-catalogue-data';
import type { Business, User } from '../lib/types';
import { Errors, Field, Heading, Loading, Status, Submit } from '../components/ui';
import { translator } from '../lib/i18n';

export default function Businesses({ user, logout }: { user: User; logout: () => void }) {
  const { data, loading, error, reload } = useData<{ data: Business[] }>('/api/businesses');
  const [create, setCreate] = useState(false);
  const [name, setName] = useState('');
  const [address, setAddress] = useState('');
  const [page, setPage] = useState(1);
  const navigate = useNavigate();
  const form = useSave();
  const t = translator(user.locale || 'en');
  const blocked = form.busy || form.uncertain;
  const creating = create || data?.data.length === 0;
  const pages = Math.max(1, Math.ceil((data?.data.length || 0) / 2));
  const current = Math.min(page, pages);
  return (
    <IonPage>
      <IonContent>
        <div className="businesses-wrap business-tools">
          <header className="businesses-header">
            <span className="brand">
              <span className="brand-mark">b</span>businessbook
            </span>
            <Link
              aria-disabled={blocked}
              onClick={(event) => {
                if (blocked) event.preventDefault();
              }}
              to="/account"
            >
              {t('My account')}
            </Link>
            <IonButton
              fill="clear"
              disabled={blocked}
              onClick={async () => {
                await request('/logout', { method: 'POST' });
                resetSession();
                logout();
              }}
            >
              {t('Sign out')}
            </IonButton>
          </header>
          <Heading
            title={t(creating ? 'Start your business book' : 'Choose your business')}
            description={creating ? undefined : t('A separate book for every business.')}
          >
            {!creating && (
              <IonButton onClick={() => setCreate(true)}>
                <IonIcon slot="start" icon={addOutline} />
                {t('New business')}
              </IonButton>
            )}
          </Heading>
          {loading ? (
            <Loading />
          ) : error ? (
            <Loading error={error} retry={reload} />
          ) : creating ? (
            <>
              <form
                className="panel business-create"
                data-dirty={name ? 'true' : 'false'}
                onSubmit={(e) => {
                  e.preventDefault();
                  void form.save<Business>('/api/businesses', { name, address }, (business) =>
                    navigate(`/app/${business.slug}/opening`),
                  );
                }}
              >
                <Errors error={form.error} />
                <fieldset className="entry-lock" disabled={blocked}>
                  <Field
                    label={t('Business name')}
                    name="name"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    required
                    maxLength={150}
                  />
                  <Field
                    label={t('Address')}
                    name="address"
                    value={address}
                    onChange={(e) => setAddress(e.target.value)}
                  />
                  <div className="business-create-actions">
                    {!!data?.data.length && (
                      <IonButton fill="clear" disabled={blocked} onClick={() => setCreate(false)}>
                        {t('Cancel')}
                      </IonButton>
                    )}
                    <Submit busy={form.busy} disabled={blocked}>
                      {t('Create business')}
                    </Submit>
                  </div>
                </fieldset>
                {form.uncertain && (
                  <IonButton disabled={form.busy} onClick={() => void form.retry()}>
                    {t('Retry original action')}
                  </IonButton>
                )}
              </form>
            </>
          ) : (
            <>
              <div className="business-grid">
                {data?.data.slice((current - 1) * 2, current * 2).map((business) => (
                  <Link
                    className="business-card panel"
                    to={`/app/${business.slug}`}
                    key={business.id}
                  >
                    <span className="business-symbol">
                      <IonIcon icon={storefrontOutline} />
                    </span>
                    <div>
                      <h2>{business.name}</h2>
                      <span className="subtle">
                        {t(business.role)} · <Status value={business.access_status} />
                      </span>
                    </div>
                    <IonIcon icon={arrowForwardOutline} />
                  </Link>
                ))}
              </div>
              {pages > 1 && (
                <nav className="home-pager" aria-label={t('Businesses')}>
                  <button
                    type="button"
                    disabled={current <= 1}
                    onClick={() => setPage(current - 1)}
                  >
                    {t('Previous businesses')}
                  </button>
                  <span>
                    {current} / {pages}
                  </span>
                  <button
                    type="button"
                    disabled={current >= pages}
                    onClick={() => setPage(current + 1)}
                  >
                    {t('Next businesses')}
                  </button>
                </nav>
              )}
            </>
          )}
        </div>
      </IonContent>
    </IonPage>
  );
}

export function Invitation({ token }: { token: string }) {
  const navigate = useNavigate();
  const [error, setError] = useState<Error>();
  const [busy, setBusy] = useState(false);
  return (
    <IonPage>
      <IonContent>
        <div className="center-screen">
          <div className="panel">
            <h1>Join your business</h1>
            <p>Your verified email must match the invitation.</p>
            <Errors error={error} />
            <IonButton
              disabled={busy}
              onClick={async () => {
                setBusy(true);
                try {
                  const response = await send<{ data: Business }>(
                    `/api/invitations/${encodeURIComponent(token)}`,
                    {},
                  );
                  navigate(`/app/${response.data.slug}`);
                } catch (e) {
                  setError(e as Error);
                } finally {
                  setBusy(false);
                }
              }}
            >
              Accept invitation
            </IonButton>
          </div>
        </div>
      </IonContent>
    </IonPage>
  );
}
