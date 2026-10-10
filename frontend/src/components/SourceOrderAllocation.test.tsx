import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it } from 'vitest';
import { SourceOrderAllocation } from './SourceOrderAllocation';

it('discloses original-order rounding and allocated discounts without claiming a complete offer', () => {
  render(
    <MemoryRouter>
      <SourceOrderAllocation
        path="/app/shop"
        t={(text) => text}
        discountPaisa="700"
        source={{
          id: '9',
          number: 'SO-000009',
          allocation: 'cumulative_source_order',
          offer: { id: '4', name: 'Agreed bundle', discount_paisa: '1000' },
          lines: [
            {
              position: 1,
              order_position: 2,
              gross_rounding_paisa: '1',
              tax_rounding_paisa: '-1',
              cost_variance_paisa: '1',
            },
          ],
        }}
      />
    </MemoryRouter>,
  );
  expect(screen.getByRole('link', { name: 'SO-000009' })).toHaveAttribute(
    'href',
    '/app/shop/workflow/9',
  );
  expect(screen.getByText(/Allocated source discounts/)).toHaveTextContent('रु 7.00');
  expect(screen.queryByText(/रु 10.00/)).not.toBeInTheDocument();
  expect(screen.getByText(/Price allocation difference/)).toHaveTextContent('रु 0.01');
  expect(screen.getByText(/Tax allocation difference/)).toHaveTextContent('रु -0.01');
  expect(screen.getByText(/Receipt cost difference/)).toHaveTextContent('रु 0.01');
});

it('keeps ordinary bills unchanged and shows return allocation from the original bill', () => {
  const view = render(
    <MemoryRouter>
      <SourceOrderAllocation path="/app/shop" t={(text) => text} discountPaisa="0" />
    </MemoryRouter>,
  );
  expect(view.container).toBeEmptyDOMElement();
  view.rerender(
    <MemoryRouter>
      <SourceOrderAllocation
        path="/app/shop"
        t={(text) => text}
        discountPaisa="0"
        source={{
          id: '9',
          number: 'SO-000009',
          source_bill_id: '20',
          allocation: 'cumulative_source_bill_return',
          lines: [],
        }}
      />
    </MemoryRouter>,
  );
  expect(screen.getByText('Original bill return allocation')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Original bill' })).toHaveAttribute(
    'href',
    '/app/shop/document/20',
  );
});
