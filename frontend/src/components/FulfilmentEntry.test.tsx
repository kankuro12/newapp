import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { Context } from '../lib/context';
import { resetSession } from '../lib/api';
import type { Business, Workflow } from '../lib/types';
import { FulfilmentEntry } from './FulfilmentEntry';

vi.mock('@ionic/react', () => ({
  IonButton: ({
    children,
    type = 'button',
    fill,
    color,
    ...props
  }: React.ButtonHTMLAttributes<HTMLButtonElement> & { fill?: string; color?: string }) => (
    <button type={type} data-fill={fill} data-color={color} {...props}>
      {children}
    </button>
  ),
  IonIcon: () => null,
  IonSpinner: () => null,
}));
const fetcher = vi.fn();
beforeEach(() => {
  resetSession();
  fetcher.mockReset();
  vi.stubGlobal('fetch', fetcher);
  fetcher.mockImplementation(async (path: string) =>
    path.includes('csrf')
      ? new Response(null, { status: 204 })
      : new Response(
          JSON.stringify({
            data: {
              fingerprint: 'a'.repeat(64),
              lines: [{ position: 1, description: 'Milk', qty_milli: '1250', unit_snapshot: 'L' }],
            },
          }),
          { status: 200 },
        ),
  );
});
const order = {
  id: '9',
  kind: 'sales_order',
  version: 2,
  fulfilment: {
    active: false,
    lines: [
      {
        position: 1,
        description: 'Milk',
        unit_snapshot: 'L',
        ordered_qty_milli: '3000',
        completed_qty_milli: '0',
        remaining_qty_milli: '3000',
        billed_qty_milli: '0',
        billed_returned_qty_milli: '0',
        billable_qty_milli: '0',
      },
    ],
    activity: [],
    bills: [],
  },
} as unknown as Workflow;
function entry(row = order, mode: 'record' | 'bill' | 'ordered-bill' = 'record', done = vi.fn()) {
  return (
    <MemoryRouter>
      <Context.Provider
        value={{
          base: '/api/app/shop',
          path: '/app/shop',
          business: { role: 'owner', opening_finalized_at: 'now' } as Business,
          today: 20830103,
          revision: 0,
          changed: vi.fn(),
          t: (s) => s,
          locale: 'en',
        }}
      >
        <FulfilmentEntry order={row} mode={mode} accounts={[]} done={done} />
      </Context.Provider>
    </MemoryRouter>
  );
}

it('requires selected quantities and invalidates review after quantity or source version changes', async () => {
  const view = render(entry());
  fireEvent.click(screen.getByRole('button', { name: 'Review quantities' }));
  expect(fetcher).not.toHaveBeenCalled();
  fireEvent.change(screen.getByLabelText('Milk · Quantity'), { target: { value: '1.250' } });
  fireEvent.click(screen.getByRole('button', { name: 'Review quantities' }));
  await screen.findByRole('button', { name: 'Confirm delivery / work' });
  fireEvent.change(screen.getByLabelText('Milk · Quantity'), { target: { value: '1.251' } });
  expect(screen.queryByRole('button', { name: 'Confirm delivery / work' })).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Review quantities' }));
  await screen.findByRole('button', { name: 'Confirm delivery / work' });
  view.rerender(entry({ ...order, version: 3 }));
  expect(screen.queryByRole('button', { name: 'Confirm delivery / work' })).not.toBeInTheDocument();
});

it('reviews ordered quantities before fulfilment and posts the exact reviewed bill proof', async () => {
  const done = vi.fn();
  fetcher.mockImplementation(async (path: string) =>
    path.includes('csrf')
      ? new Response(null, { status: 204 })
      : path.endsWith('/ordered-bills')
        ? new Response(JSON.stringify({ data: { id: '99' } }), { status: 201 })
        : new Response(
            JSON.stringify({
              data: {
                fingerprint: 'c'.repeat(64),
                total_paisa: '12500',
                tax_paisa: '0',
                line_discount_paisa: '0',
                invoice_discount_paisa: '0',
                lines: [
                  {
                    position: 1,
                    description: 'Milk',
                    qty_milli: '1250',
                    unit_snapshot: 'L',
                    total_paisa: '12500',
                  },
                ],
              },
            }),
            { status: 200 },
          ),
  );
  render(entry(order, 'ordered-bill', done));
  expect(screen.getByRole('heading', { name: 'Bill ordered quantities' })).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('Milk · Quantity'), { target: { value: '1.250' } });
  fireEvent.change(screen.getByLabelText('Paid now'), { target: { value: '0' } });
  fireEvent.click(screen.getByRole('button', { name: 'Review quantities' }));
  await screen.findByRole('button', { name: 'Confirm bill + payment' });
  const previewCall = fetcher.mock.calls.find((call) =>
    String(call[0]).endsWith('/ordered-bills/preview'),
  )!;
  expect(JSON.parse(previewCall[1].body)).toMatchObject({
    version: 2,
    lines: [{ position: 1, qty: '1.250' }],
    paid_now: '0',
  });
  fireEvent.click(screen.getByRole('button', { name: 'Confirm bill + payment' }));
  await waitFor(() => expect(done).toHaveBeenCalledWith('99'));
  const posted = fetcher.mock.calls.find((call) => String(call[0]).endsWith('/ordered-bills'))!;
  expect(JSON.parse(posted[1].body)).toMatchObject({
    version: 2,
    lines: [{ position: 1, qty: '1.250' }],
    expected_total_paisa: '12500',
    expected_fingerprint: 'c'.repeat(64),
  });
  expect(fetcher.mock.calls.some((call) => String(call[0]).includes('/fulfilments'))).toBe(false);
});

it('limits ordered billing to unbilled order quantity rather than completed stock', async () => {
  const partlyBilled = {
    ...order,
    fulfilment: {
      ...order.fulfilment!,
      lines: order.fulfilment!.lines.map((line) => ({
        ...line,
        billed_qty_milli: '1250',
        completed_qty_milli: '0',
        billable_qty_milli: '0',
      })),
    },
  } as Workflow;
  render(entry(partlyBilled, 'ordered-bill'));
  expect(screen.getByText('Available: 1.750 L')).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('Milk · Quantity'), { target: { value: '1.751' } });
  fireEvent.click(screen.getByRole('button', { name: 'Review quantities' }));
  expect(await screen.findByRole('alert')).toHaveTextContent(
    'Quantity must be positive and within available quantity.',
  );
  expect(fetcher).not.toHaveBeenCalled();
});

it('locks a lost ordered-bill reply and retries its original quantity version proof and UUID', async () => {
  const done = vi.fn();
  fetcher.mockImplementation(async (path: string) =>
    path.includes('csrf')
      ? new Response(null, { status: 204 })
      : new Response(
          JSON.stringify({
            data: {
              fingerprint: 'd'.repeat(64),
              total_paisa: '12500',
              tax_paisa: '0',
              lines: [
                {
                  description: 'Milk',
                  qty_milli: '1250',
                  unit_snapshot: 'L',
                  total_paisa: '12500',
                },
              ],
            },
          }),
          { status: 200 },
        ),
  );
  const view = render(entry(order, 'ordered-bill', done));
  fireEvent.change(screen.getByLabelText('Milk · Quantity'), { target: { value: '1.250' } });
  fireEvent.click(screen.getByRole('button', { name: 'Review quantities' }));
  await screen.findByRole('button', { name: 'Confirm bill + payment' });
  fetcher.mockRejectedValueOnce(new Error('Lost reply'));
  fireEvent.click(screen.getByRole('button', { name: 'Confirm bill + payment' }));
  await screen.findByRole('button', { name: 'Retry original action' });
  const original = JSON.parse(fetcher.mock.calls.at(-1)![1].body);
  view.rerender(entry({ ...order, version: 3 }, 'ordered-bill', done));
  expect(screen.getByLabelText('Milk · Quantity')).toBeDisabled();
  expect(screen.getByLabelText('Paid now')).toBeDisabled();
  expect(screen.getByRole('button', { name: 'Close entry' })).toBeDisabled();
  fetcher.mockResolvedValueOnce(
    new Response(JSON.stringify({ data: { id: '99' } }), { status: 201 }),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Retry original action' }));
  await waitFor(() => expect(done).toHaveBeenCalledWith('99'));
  expect(JSON.parse(fetcher.mock.calls.at(-1)![1].body)).toEqual(original);
  expect(original).toMatchObject({
    version: 2,
    expected_total_paisa: '12500',
    expected_fingerprint: 'd'.repeat(64),
  });
  expect(original.mutation_uuid).toMatch(/^[a-f0-9-]{36}$/);
});

it('keeps uncertain save locked and retries original quantity, proof and UUID', async () => {
  const done = vi.fn();
  const view = render(entry(order, 'record', done));
  fireEvent.change(screen.getByLabelText('Milk · Quantity'), { target: { value: '1.250' } });
  fireEvent.click(screen.getByRole('button', { name: 'Review quantities' }));
  await screen.findByRole('button', { name: 'Confirm delivery / work' });
  fetcher.mockRejectedValueOnce(new Error('Lost reply'));
  fireEvent.click(screen.getByRole('button', { name: 'Confirm delivery / work' }));
  await screen.findByRole('button', { name: 'Retry original action' });
  expect(screen.getByLabelText('Milk · Quantity')).toBeDisabled();
  expect(screen.getByRole('button', { name: 'Close entry' })).toBeDisabled();
  const first = JSON.parse(fetcher.mock.calls.at(-1)![1].body);
  view.rerender(
    entry(
      {
        ...order,
        version: 3,
        fulfilment: {
          ...order.fulfilment!,
          lines: order.fulfilment!.lines.map((line) => ({ ...line, remaining_qty_milli: '0' })),
        },
      },
      'record',
      done,
    ),
  );
  expect(screen.getByText('1.250 L')).toBeInTheDocument();
  fetcher.mockResolvedValueOnce(
    new Response(JSON.stringify({ data: { id: '8' } }), { status: 201 }),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Retry original action' }));
  await waitFor(() => expect(done).toHaveBeenCalledOnce());
  expect(JSON.parse(fetcher.mock.calls.at(-1)![1].body)).toEqual(first);
  expect(first).toMatchObject({
    version: 2,
    lines: [{ position: 1, qty: '1.250' }],
    expected_fingerprint: 'a'.repeat(64),
  });
  expect(first.mutation_uuid).toMatch(/^[a-f0-9-]{36}$/);
});

it('invalidates a bill review when paid amount changes without changing invoice total', async () => {
  const billed = {
    ...order,
    fulfilment: {
      ...order.fulfilment!,
      active: true,
      activity: [
        {
          id: '8',
          number: 'DEL-000008',
          kind: 'delivery',
          status: 'posted',
          business_date_bs: 20830103,
          version: 1,
          lines: [
            {
              id: '81',
              position: 1,
              qty_milli: '3000',
              billable_qty_milli: '3000',
              returnable_qty_milli: '3000',
              item_snapshot: { description: 'Milk', unit_snapshot: 'L' },
            },
          ],
        },
      ],
    },
  } as unknown as Workflow;
  fetcher.mockImplementation(async (path: string) =>
    path.includes('csrf')
      ? new Response(null, { status: 204 })
      : new Response(
          JSON.stringify({
            data: {
              fingerprint: 'b'.repeat(64),
              total_paisa: '10000',
              line_discount_paisa: '0',
              invoice_discount_paisa: '0',
              tax_paisa: '0',
              source_order_snapshot: {
                id: '9',
                number: 'SO-9',
                allocation: 'cumulative_source_order',
                lines: [],
              },
              lines: [
                {
                  description: 'Milk',
                  qty_milli: '1250',
                  unit_snapshot: 'L',
                  total_paisa: '10000',
                },
              ],
            },
          }),
          { status: 200 },
        ),
  );
  render(entry(billed, 'bill'));
  fireEvent.change(screen.getByLabelText('Milk · DEL-000008 · Quantity'), {
    target: { value: '1.250' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Review quantities' }));
  await screen.findByRole('button', { name: 'Confirm bill + payment' });
  fireEvent.change(screen.getByLabelText('Paid now'), { target: { value: '20' } });
  expect(screen.queryByRole('button', { name: 'Confirm bill + payment' })).not.toBeInTheDocument();
});
