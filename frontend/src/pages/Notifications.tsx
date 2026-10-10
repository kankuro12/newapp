import { IonButton, IonContent, IonPage } from '@ionic/react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { request, send, useData } from '../lib/api';
import { enablePush, revokeCurrentPush } from '../lib/push';
import { Errors, Heading, Loading } from '../components/ui';
type Preferences = Record<
  | 'operational_email'
  | 'promotional_email'
  | 'operational_fcm'
  | 'promotional_fcm'
  | 'operational_whatsapp'
  | 'promotional_whatsapp',
  boolean
>;
interface Device {
  id: string;
  name: string;
  active: boolean;
}
const labels: [keyof Preferences, string][] = [
  ['operational_email', 'Package email reminders'],
  ['operational_fcm', 'Package push reminders'],
  ['operational_whatsapp', 'Package WhatsApp reminders'],
  ['promotional_email', 'Email promotions'],
  ['promotional_fcm', 'Push promotions'],
  ['promotional_whatsapp', 'WhatsApp promotions'],
];
export default function Notifications({ userId }: { userId: string }) {
  const preferences = useData<{ data: Preferences }>('/api/notifications/preferences');
  const devices = useData<{ data: Device[] }>('/api/notifications/devices');
  const [changed, setChanged] = useState<Partial<Preferences>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<Error>();
  const [notice, setNotice] = useState('');
  async function action(callback: () => Promise<void>, message: string) {
    setBusy(true);
    setError(undefined);
    setNotice('');
    try {
      await callback();
      setNotice(message);
      preferences.reload();
      devices.reload();
    } catch (cause) {
      setError(cause as Error);
    } finally {
      setBusy(false);
    }
  }
  return (
    <IonPage>
      <IonContent>
        <div className="businesses-wrap">
          <header className="businesses-header">
            <Link to="/account">My account</Link>
            <Link to="/businesses">Businesses</Link>
          </header>
          <Heading
            title="Notifications"
            description="Choose package reminders and optional promotions for your account."
          />
          <Errors error={error} />
          {notice && <p role="status">{notice}</p>}
          <section className="panel section">
            <h2>Delivery preferences</h2>
            <p>
              Security emails and payment receipts remain available. Promotions require your choice.
              WhatsApp uses your account phone when configured.
            </p>
            {preferences.loading || preferences.error ? (
              <Loading error={preferences.error} retry={preferences.reload} />
            ) : (
              preferences.data &&
              labels.map(([key, label]) => (
                <label className="check" key={key}>
                  <input
                    type="checkbox"
                    disabled={busy}
                    checked={changed[key] ?? preferences.data!.data[key]}
                    onChange={(event) => {
                      const value = event.target.checked;
                      void action(async () => {
                        await send('/api/notifications/preferences', { [key]: value }, 'PATCH');
                        setChanged((previous) => ({ ...previous, [key]: value }));
                      }, 'Preferences saved.');
                    }}
                  />
                  {label}
                </label>
              ))
            )}
          </section>
          <section className="panel section">
            <h2>Push on this browser</h2>
            <p>
              Enable from this button, then allow browser notifications. HTTPS and a supported
              browser are required.
            </p>
            <IonButton
              disabled={busy}
              onClick={() => void action(() => enablePush(userId), 'Push enabled on this device.')}
            >
              Enable push on this device
            </IonButton>
            <IonButton
              disabled={busy}
              fill="outline"
              onClick={() =>
                void action(
                  () => revokeCurrentPush(userId),
                  'Push registration removed from this device.',
                )
              }
            >
              Disable push on this device
            </IonButton>
          </section>
          <section className="panel section">
            <h2>Your devices</h2>
            {devices.loading || devices.error ? (
              <Loading error={devices.error} retry={devices.reload} />
            ) : devices.data?.data.length ? (
              devices.data.data.map((device) => (
                <div className="mini-row" key={device.id}>
                  <span>
                    {device.name}
                    <small>{device.active ? 'Active' : 'Revoked'}</small>
                  </span>
                  {device.active && (
                    <IonButton
                      disabled={busy}
                      fill="clear"
                      onClick={() =>
                        void action(async () => {
                          await request('/api/notifications/devices/' + device.id, {
                            method: 'DELETE',
                          });
                        }, 'Device revoked.')
                      }
                    >
                      Revoke device
                    </IonButton>
                  )}
                </div>
              ))
            ) : (
              <p>No registered devices.</p>
            )}
          </section>
        </div>
      </IonContent>
    </IonPage>
  );
}
