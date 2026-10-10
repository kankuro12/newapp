import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { ScanItem } from './BarcodeTools';
import { Context } from '../lib/context';
import type { Business } from '../lib/types';

const called = vi.hoisted(() =>
  vi.fn(async (path: string, options?: RequestInit) => {
    if (options?.signal?.aborted) throw new Error('Aborted');
    if (path.endsWith('/pos/scan'))
      return {
        data: {
          item_id: '5',
          measurement: {
            mode: 'quantity',
            value: '0.375',
            unit: 'kg',
            barcode: '2000501003751',
            barcode_fingerprint: 'a'.repeat(64),
          },
        },
      };
    return { data: { id: '5', name: 'Meat', kind: 'stock', pos_unit: 'kg' } };
  }),
);
vi.mock('../lib/api', () => ({
  ApiError: class extends Error {},
  request: called,
  useData: () => ({ loading: false }),
  useSave: () => ({}),
  useOnline: () => true,
}));
vi.mock('@ionic/react', () => ({
  IonButton: ({
    children,
    type = 'button',
    ...props
  }: React.ButtonHTMLAttributes<HTMLButtonElement>) => (
    <button type={type} {...props}>
      {children}
    </button>
  ),
  IonIcon: () => null,
  IonSpinner: () => null,
}));
afterEach(() => vi.clearAllMocks());

it('waits for scoped server resolution and keeps raw code provenance', async () => {
  const picked = vi.fn();
  render(
    <Context.Provider
      value={{
        base: '/api/app/shop',
        path: '/app/shop',
        business: { role: 'owner' } as Business,
        today: 20830103,
        revision: 0,
        changed: () => {},
        t: (s) => s,
        locale: 'en',
      }}
    >
      <ScanItem onScan={picked} />
    </Context.Provider>,
  );
  fireEvent.change(screen.getByLabelText('Scan / enter product code'), {
    target: { value: '2000501003751' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Add scanned item' }));
  expect(picked).not.toHaveBeenCalled();
  expect(screen.getByLabelText('Scan / enter product code')).not.toBeDisabled();
  expect(screen.getByLabelText('Scan / enter product code')).toHaveProperty('readOnly', true);
  await waitFor(() => expect(picked).toHaveBeenCalledOnce());
  expect(picked.mock.calls[0][1]).toMatchObject({
    barcode: '2000501003751',
    barcode_fingerprint: 'a'.repeat(64),
    value: '0.375',
  });
  expect(JSON.parse(called.mock.calls[0][1]!.body as string)).toEqual({
    code: '2000501003751',
    contact_id: null,
  });
  expect(screen.getByLabelText('Scan / enter product code')).toHaveValue('');
  expect(screen.getByLabelText('Scan / enter product code')).toHaveProperty('readOnly', false);
});

it('keeps rejected code for correction and never adds an unverified item', async () => {
  called.mockRejectedValueOnce(new Error('Barcode checksum invalid.'));
  const picked = vi.fn();
  render(
    <Context.Provider
      value={{
        base: '/api/app/shop',
        path: '/app/shop',
        business: { role: 'owner' } as Business,
        today: 20830103,
        revision: 0,
        changed: () => {},
        t: (s) => s,
        locale: 'en',
      }}
    >
      <ScanItem onScan={picked} />
    </Context.Provider>,
  );
  fireEvent.change(screen.getByLabelText('Scan / enter product code'), {
    target: { value: '2000501003752' },
  });
  fireEvent.keyDown(screen.getByLabelText('Scan / enter product code'), { key: 'Enter' });
  await screen.findByText('Barcode checksum invalid.');
  expect(picked).not.toHaveBeenCalled();
  expect(called).toHaveBeenCalledOnce();
  expect(screen.getByLabelText('Scan / enter product code')).toHaveValue('2000501003752');
});
