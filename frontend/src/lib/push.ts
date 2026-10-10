import { request, send } from './api';
import { firebaseConfig, pushConfigured } from './firebaseConfig';
const ownershipKey = 'bb-push-registration';
const uuidKey = 'bb-push-device';
interface Registration {
  user: string;
  id: string;
}
function stored(): Registration | undefined {
  try {
    const value = JSON.parse(localStorage.getItem(ownershipKey) || 'null');
    return typeof value?.user === 'string' && typeof value?.id === 'string' ? value : undefined;
  } catch {
    return undefined;
  }
}
async function messaging() {
  const [app, fcm] = await Promise.all([import('@firebase/app'), import('@firebase/messaging')]);
  if (!(await fcm.isSupported())) throw new Error('Browser does not support push notifications.');
  const instance =
    app.getApps().find((value) => value.name === 'business-book-push') ||
    app.initializeApp(firebaseConfig(), 'business-book-push');
  return { fcm, instance: fcm.getMessaging(instance) };
}
export async function enablePush(user: string): Promise<void> {
  if (!pushConfigured()) throw new Error('Push notifications are not configured yet.');
  if (!window.isSecureContext || !('Notification' in window) || !('serviceWorker' in navigator))
    throw new Error('Browser does not support secure push notifications.');
  // Permission request stays directly inside the user's button action.
  const permission = await Notification.requestPermission();
  if (permission !== 'granted')
    throw new Error(
      'Notification permission was not granted. Change browser permission to enable push.',
    );
  const { fcm, instance } = await messaging();
  await navigator.serviceWorker.register('/sw.js', { type: 'module' });
  let workerTimer: ReturnType<typeof setTimeout> | undefined;
  const worker = await Promise.race([
    navigator.serviceWorker.ready,
    new Promise<never>((_, reject) => {
      workerTimer = setTimeout(
        () => reject(new Error('Push worker did not activate. Reopen app and try again.')),
        20000,
      );
    }),
  ]).finally(() => clearTimeout(workerTimer));
  const fid = await new Promise<string>((resolve, reject) => {
    let completed = false;
    let unsubscribe = () => undefined as void;
    const timer = setTimeout(() => {
      unsubscribe();
      reject(new Error('Push registration timed out. Retry enable push.'));
    }, 20000);
    unsubscribe = fcm.onRegistered(instance, (value) => {
      if (completed) return;
      completed = true;
      clearTimeout(timer);
      unsubscribe();
      resolve(value);
    });
    if (completed) unsubscribe();
    void fcm
      .register(instance, {
        vapidKey: import.meta.env.VITE_FIREBASE_VAPID_KEY,
        serviceWorkerRegistration: worker,
      })
      .catch((error) => {
        clearTimeout(timer);
        unsubscribe();
        reject(error);
      });
  });
  let uuid = localStorage.getItem(uuidKey);
  if (!uuid) {
    uuid = crypto.randomUUID();
    localStorage.setItem(uuidKey, uuid);
  }
  const response = await send<{ data: { id: string } }>('/api/notifications/devices', {
    device_uuid: uuid,
    token: fid,
    target_kind: 'fid',
    name: 'Web browser',
    consent: true,
  });
  localStorage.setItem(ownershipKey, JSON.stringify({ user, id: response.data.id }));
  window.dispatchEvent(new Event('bb-push-change'));
}
export async function revokeCurrentPush(user: string): Promise<void> {
  const registration = stored();
  if (!registration || registration.user !== user) return;
  await request('/api/notifications/devices/' + registration.id, { method: 'DELETE' });
  localStorage.removeItem(ownershipKey);
  window.dispatchEvent(new Event('bb-push-change'));
  if (pushConfigured()) {
    const { fcm, instance } = await messaging();
    await fcm.unregister(instance);
  }
}
export async function listenForPush(
  user: string,
  receive: (title: string, body: string) => void,
): Promise<() => void> {
  if (!pushConfigured() || stored()?.user !== user || Notification.permission !== 'granted')
    return () => undefined;
  const { fcm, instance } = await messaging();
  return fcm.onMessage(instance, (payload) =>
    receive(
      payload.notification?.title || 'Business Book',
      payload.notification?.body || 'Open your account to review updates.',
    ),
  );
}
export async function tenantSignOut(user: string): Promise<void> {
  await revokeCurrentPush(user);
  const uuid = localStorage.getItem(uuidKey);
  await request('/logout', {
    method: 'POST',
    ...(uuid ? { body: JSON.stringify({ push_device_uuid: uuid }) } : {}),
  });
}
