import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { PriceListEditor, ReviewedPrices } from './PriceLists';
import { Context } from '../lib/context';
import type { Business, PriceList } from '../lib/types';

const state = vi.hoisted(() => ({
  saved: vi.fn(),
  detail: undefined as PriceList | undefined,
  uncertain: false,
  retry: vi.fn(),
  request: vi.fn(async (_path: string) => {
    void _path;
    return { data: [{ item_id: '5', price_paisa: '8025' }] };
  }),
}));
vi.mock('../lib/api', () => ({
  ApiError: class extends Error {},
  request: state.request,
  useData: () => ({
    loading: false,
    data: state.detail ? { data: state.detail } : undefined,
    reload: vi.fn(),
  }),
  useSave: () => ({
    busy: false,
    error: undefined,
    uncertain: state.uncertain,
    save: state.saved,
    retry: state.retry,
  }),
  useOnline: () => true,
}));
vi.mock('../components/Picker', () => ({
  default: ({ onPick }: { onPick: (item: object) => void }) => (
    <button
      type="button"
      onClick={() =>
        onPick({
          id: '5',
          name: 'Work',
          kind: 'service',
          unit_label: 'job',
          pos_unit: 'unit',
          sale_price_paisa: '10001',
        })
      }
    >
      Choose product
    </button>
  ),
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
function editor(id = 'new') {
  return render(
    <MemoryRouter initialEntries={['/app/shop/price-lists/' + id]}>
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
        <Routes>
          <Route path="/app/shop/price-lists/new" element={<PriceListEditor />} />
          <Route path="/app/shop/price-lists/:id" element={<PriceListEditor />} />
        </Routes>
      </Context.Provider>
    </MemoryRouter>,
  );
}

it('sends exact reviewed decimal tiers with item units and retains failed draft', async () => {
  state.uncertain = false;
  state.saved.mockClear();
  editor();
  fireEvent.change(screen.getByLabelText('List name'), { target: { value: 'Wholesale' } });
  fireEvent.click(screen.getByRole('button', { name: 'Choose product' }));
  fireEvent.click(screen.getByRole('button', { name: 'Add tier for Work' }));
  fireEvent.change(screen.getAllByLabelText('Minimum quantity (job)')[1], {
    target: { value: '2.500' },
  });
  fireEvent.change(screen.getAllByLabelText('Unit price (NPR)')[1], { target: { value: '80.25' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save price list' }));
  await waitFor(() => expect(state.saved).toHaveBeenCalledOnce());
  expect(state.saved.mock.calls[0][1].rules).toEqual([
    {
      item_id: '5',
      min_qty: '0',
      price: '100.01',
      unit_snapshot: 'job',
      pos_unit: 'unit',
      item_kind: 'service',
    },
    {
      item_id: '5',
      min_qty: '2.500',
      price: '80.25',
      unit_snapshot: 'job',
      pos_unit: 'unit',
      item_kind: 'service',
    },
  ]);
  expect(screen.getByLabelText('List name')).toHaveValue('Wholesale');
  expect(screen.getAllByLabelText('Unit price (NPR)')[1]).toHaveValue('80.25');
});

it('locks uncertain draft and retains original retry', () => {
  state.uncertain = true;
  state.retry.mockClear();
  editor();
  expect(screen.getByLabelText('List name')).toBeDisabled();
  expect(screen.getByRole('button', { name: 'Save price list' })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Retry original action' }));
  expect(state.retry).toHaveBeenCalledOnce();
  state.uncertain = false;
});

it('reviews and saves chosen slab scheme with zero-based tiers', async () => {
  state.detail = undefined;
  state.saved.mockReset();
  state.uncertain = false;
  editor();
  fireEvent.change(screen.getByLabelText('List name'), { target: { value: 'Graduated' } });
  fireEvent.change(screen.getByLabelText('Quantity pricing method'), { target: { value: 'slab' } });
  fireEvent.click(screen.getByRole('button', { name: 'Choose product' }));
  fireEvent.click(screen.getByRole('button', { name: 'Add tier for Work' }));
  fireEvent.change(screen.getAllByLabelText('Minimum quantity (job)')[1], {
    target: { value: '2.500' },
  });
  fireEvent.change(screen.getAllByLabelText('Unit price (NPR)')[1], { target: { value: '80.25' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save price list' }));
  await waitFor(() => expect(state.saved).toHaveBeenCalledOnce());
  expect(state.saved.mock.calls[0][1].pricing_scheme).toBe('slab');
  expect(
    screen.getByText(
      'Each range uses its own price. Start every item at zero; the next minimum ends the previous range.',
    ),
  ).toBeVisible();
});

it('uses confirmed saved version for the next edit without adopting background revisions', () => {
  state.saved.mockReset();
  state.uncertain = false;
  state.detail = {
    id: '7',
    name: 'Wholesale',
    channel: 'sale',
    enabled: true,
    version: 1,
    adjustment_bps: '0',
    starts_bs: null,
    ends_bs: null,
    rules: [],
  };
  state.saved.mockImplementationOnce(
    (_path: string, _input: unknown, done: (row: PriceList) => void) => {
      void _path;
      void _input;
      done({ ...state.detail!, version: 2 });
    },
  );
  const view = editor('7');
  fireEvent.click(screen.getByRole('button', { name: 'Save price list' }));
  expect(state.saved.mock.calls[0][1].version).toBe(1);
  state.detail = { ...state.detail, version: 9, name: 'Remote edit' };
  fireEvent.change(screen.getByLabelText('List name'), { target: { value: 'Second edit' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save price list' }));
  expect(state.saved.mock.calls[1][1]).toMatchObject({ version: 2, name: 'Second edit' });
  view.unmount();
  state.detail = undefined;
});

it('reviews exact quantities and invalidates changed selection before apply', async () => {
  const apply = vi.fn();
  state.request.mockClear();
  function proof(qty: string) {
    return (
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
          <ReviewedPrices
            lines={[
              { item_id: '5', qty: '1' },
              { item_id: '5', qty },
            ]}
            channel="sale"
            date="2083-01-03"
            listId="7"
            onApply={apply}
          />
        </Context.Provider>
      </MemoryRouter>
    );
  }
  const view = render(proof('1.500'));
  expect(screen.getByRole('button', { name: 'Apply reviewed prices' })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Review quantity prices' }));
  await waitFor(() =>
    expect(screen.getByRole('button', { name: 'Apply reviewed prices' })).toBeEnabled(),
  );
  const query = new URL(state.request.mock.calls[0][0] as string, 'http://local').searchParams;
  expect(query.get('quantities[5]')).toBe('2.500');
  expect(query.get('price_list_id')).toBe('7');
  view.rerender(proof('3'));
  expect(screen.getByRole('button', { name: 'Apply reviewed prices' })).toBeDisabled();
  expect(apply).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button', { name: 'Review quantity prices' }));
  await waitFor(() =>
    expect(screen.getByRole('button', { name: 'Apply reviewed prices' })).toBeEnabled(),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Apply reviewed prices' }));
  expect(apply).toHaveBeenCalledWith([{ item_id: '5', price_paisa: '8025' }]);
});
