import { beforeEach, expect, it, vi } from 'vitest';
const mocks = vi.hoisted(() => ({
  send: vi.fn(),
  request: vi.fn(),
  register: vi.fn(),
  unregister: vi.fn(),
  onRegistered: vi.fn(),
  supported: vi.fn(),
}));
vi.mock('./api', () => ({ send: mocks.send, request: mocks.request }));
vi.mock('@firebase/app', () => ({ getApps: () => [], initializeApp: () => ({}) }));
vi.mock('@firebase/messaging', () => ({
  getMessaging: () => ({}),
  isSupported: mocks.supported,
  register: mocks.register,
  unregister: mocks.unregister,
  onRegistered: mocks.onRegistered,
  onMessage: () => () => undefined,
}));
import { enablePush, revokeCurrentPush, tenantSignOut } from './push';
beforeEach(() => {
  vi.clearAllMocks();
  localStorage.clear();
  for (const key of ['API_KEY', 'PROJECT_ID', 'APP_ID', 'MESSAGING_SENDER_ID', 'VAPID_KEY'])
    vi.stubEnv('VITE_FIREBASE_' + key, 'configured');
  Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
  Object.defineProperty(window, 'Notification', {
    value: { permission: 'default', requestPermission: vi.fn().mockResolvedValue('granted') },
    configurable: true,
  });
  Object.defineProperty(navigator, 'serviceWorker', {
    value: { register: vi.fn().mockResolvedValue({}), ready: Promise.resolve({ active: {} }) },
    configurable: true,
  });
  mocks.supported.mockResolvedValue(true);
  mocks.register.mockResolvedValue(undefined);
  mocks.unregister.mockResolvedValue(undefined);
  mocks.onRegistered.mockImplementation((_m, callback) => {
    queueMicrotask(() => callback('installation-id'));
    return vi.fn();
  });
  mocks.send.mockResolvedValue({ data: { id: '12' } });
  mocks.request.mockResolvedValue(undefined);
});
it('permission denial never registers or uploads device', async () => {
  vi.mocked(Notification.requestPermission).mockResolvedValue('denied');
  await expect(enablePush('7')).rejects.toThrow('permission');
  expect(mocks.register).not.toHaveBeenCalled();
  expect(mocks.send).not.toHaveBeenCalled();
});
it('registers FID only after explicit permission and revokes server before unregister', async () => {
  await enablePush('7');
  expect(mocks.send).toHaveBeenCalledWith(
    '/api/notifications/devices',
    expect.objectContaining({ token: 'installation-id', target_kind: 'fid', consent: true }),
  );
  await revokeCurrentPush('7');
  expect(mocks.request).toHaveBeenCalledWith('/api/notifications/devices/12', { method: 'DELETE' });
  expect(mocks.unregister).toHaveBeenCalledOnce();
  expect(mocks.request.mock.invocationCallOrder[0]).toBeLessThan(
    mocks.unregister.mock.invocationCallOrder[0],
  );
});
it('unsupported browser makes no Firebase registration', async () => {
  mocks.supported.mockResolvedValue(false);
  await expect(enablePush('7')).rejects.toThrow('support');
  expect(mocks.register).not.toHaveBeenCalled();
});
it('another signed-in user cannot revoke stored device ownership', async () => {
  await enablePush('7');
  await revokeCurrentPush('8');
  expect(mocks.request).not.toHaveBeenCalled();
  expect(mocks.unregister).not.toHaveBeenCalled();
});
it('logout includes stable device UUID when enrollment response is lost', async () => {
  mocks.send.mockRejectedValueOnce(new Error('Connection lost'));
  await expect(enablePush('7')).rejects.toThrow('Connection lost');
  const uuid = localStorage.getItem('bb-push-device');
  await tenantSignOut('7');
  expect(mocks.request).toHaveBeenCalledWith('/logout', {
    method: 'POST',
    body: JSON.stringify({ push_device_uuid: uuid }),
  });
});
