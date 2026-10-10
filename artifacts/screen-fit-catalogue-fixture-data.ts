export { ApiError } from './api';
const parameters = new URLSearchParams(location.search);
const party = {
  id: '7',
  name: 'Sample party',
  phone: '',
  is_customer: true,
  is_supplier: true,
  is_employee: true,
  is_rent: true,
  receivable_paisa: '10125',
  payable_paisa: '25000',
};
const item = {
  id: '7',
  name: 'Sample glass',
  kind: 'stock',
  unit_label: 'sq_ft',
  pos_unit: 'sq_ft',
  sale_price_paisa: '10025',
  qty_milli: '3000',
  value_paisa: '10000',
  low_stock_qty_milli: '0',
  pos_methods: ['quantity', 'amount', 'pack', 'length', 'area', 'volume'],
  pos_custom_units: [],
};
const partyResponse = { data: party };
const itemResponse = { data: item };
const branches = {
  data: Array.from({ length: 5 }, (_, i) => ({
    id: String(i + 1),
    name: 'Sample branch ' + (i + 1),
    slug: 'sample-' + (i + 1),
    role: 'owner',
    access_status: 'active',
  })),
};
const lookup = {
  data: {
    accounts: [{ id: '1', name: 'Cash' }],
    categories: [],
    contacts: [party],
    items: [item],
    item_categories: [],
    channels: { payables_id: '2' },
  },
};
const overview = {
  data: {
    assets_paisa: '100000',
    liabilities_paisa: '25000',
    equity_paisa: '75000',
    sales_paisa: '30000',
    cogs_paisa: '5000',
    profit_paisa: '25000',
    inventory_paisa: '10000',
    cash_paisa: '10125',
    receivables_paisa: '20125',
    customer_credits_paisa: '0',
    payables_paisa: '25125',
    supplier_advances_paisa: '0',
    bank_overdrafts_paisa: '0',
  },
};
const rows = {
  data: Array.from({ length: 5 }, (_, i) => ({
    name: 'Sample party ' + (i + 1),
    due_paisa: '12525',
    business_date_bs: 20830103,
  })),
};
const statement = { data: { rows: rows.data, opening_paisa: '10000', closing_paisa: '72625' } };
export function useData<T>(url: string | null) {
  let data: unknown;
  if (url === null) data = undefined;
  else if (url === '/api/businesses') data = branches;
  else if (url.includes('/reports/'))
    data =
      url.includes('/overview?') || url.includes('/balance-sheet?')
        ? overview
        : url.includes('/statement?')
          ? statement
          : rows;
  else if (url.includes('/lookup')) data = lookup;
  else if (url.endsWith('/7'))
    data = parameters.get('screen') === 'contact' ? partyResponse : itemResponse;
  return { data: data as T | undefined, loading: false, error: undefined, reload: () => {} };
}
export function useSave() {
  return {
    busy: false,
    uncertain: false,
    error: undefined,
    save: () => {
      throw new Error('Preview cannot submit');
    },
    retry: async () => {},
    clear: () => {},
  };
}
export async function send() {
  throw new Error('Preview cannot submit');
}
export async function request() {
  throw new Error('Preview cannot submit');
}
export function resetSession() {}
