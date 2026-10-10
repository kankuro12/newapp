import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import LabelTools from './LabelTools';
import { Context } from '../lib/context';
import type { Business } from '../lib/types';

const sent = vi.hoisted(() =>
  vi.fn(async (_path: string, options?: RequestInit) => {
    const input = JSON.parse(options!.body as string);
    return {
      data: {
        rows: input.rows.map((row: object) => ({
          ...row,
          name: 'Meat',
          unit_label: 'kg',
          price_paisa: '12345',
          tax_paisa: '0',
        })),
        version: '17',
      },
    };
  }),
);
vi.mock('../lib/api', () => ({
  ApiError: class extends Error {},
  request: sent,
  useOnline: () => true,
}));
vi.mock('../components/Picker', () => ({
  default: ({ onPick, kind }: { onPick: (item: object) => void; kind: string }) => (
    <button
      type="button"
      onClick={() =>
        onPick({
          id: '5',
          name: 'Meat',
          sku: '00501',
          aliases: ['00000123'],
          sale_price_paisa: '10000',
          unit_label: 'kg',
        })
      }
    >
      {kind === 'items' ? 'Choose product' : 'Choose supplier'}
    </button>
  ),
}));
vi.mock('./CatalogTools', () => ({ CategoryFilter: () => null }));
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

it('invalidates edited preview and refreshes canonical rows before printing', async () => {
  const printed = vi.spyOn(window, 'print').mockImplementation(() => {});
  render(
    <MemoryRouter>
      <Context.Provider
        value={{
          base: '/api/app/shop',
          path: '/app/shop',
          business: { id: '1', role: 'owner' } as Business,
          today: 20830103,
          revision: 0,
          changed: () => {},
          t: (s) => s,
          locale: 'en',
        }}
      >
        <LabelTools />
      </Context.Provider>
    </MemoryRouter>,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Choose product' }));
  fireEvent.change(screen.getByLabelText('Barcode: Meat'), { target: { value: '00000123' } });
  fireEvent.click(screen.getByRole('button', { name: 'Preview labels' }));
  await waitFor(() => expect(screen.getByRole('button', { name: 'Print labels' })).toBeEnabled());
  expect(screen.getAllByRole('img', { name: 'Barcode 00000123' })).toHaveLength(1);
  expect(JSON.parse(sent.mock.calls[0][1]!.body as string)).toEqual({
    rows: [{ item_id: '5', code: '00000123', copies: 1 }],
    include_tax: true,
  });
  fireEvent.change(screen.getByLabelText('Copies: Meat'), { target: { value: '2' } });
  expect(screen.getByRole('button', { name: 'Print labels' })).toBeDisabled();
  expect(screen.queryByRole('img', { name: 'Barcode 00000123' })).toBeNull();
  fireEvent.click(screen.getByRole('button', { name: 'Preview labels' }));
  await waitFor(() => expect(screen.getByRole('button', { name: 'Print labels' })).toBeEnabled());
  fireEvent.click(screen.getByRole('button', { name: 'Print labels' }));
  await waitFor(() => expect(printed).toHaveBeenCalledOnce());
  expect(sent).toHaveBeenCalledTimes(3);
  expect(screen.getAllByRole('img', { name: 'Barcode 00000123' })).toHaveLength(2);
});
