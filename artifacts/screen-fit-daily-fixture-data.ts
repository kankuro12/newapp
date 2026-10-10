export { ApiError } from './api';
const lookup = {
  accounts: [
    { id: '1', name: 'Cash' },
    { id: '2', name: 'Bank' },
  ],
  categories: [],
};
const party = { id: '9', name: 'Sample customer' };
const item = { id: '5', name: 'Rice', kind: 'stock', qty_milli: '2000', unit_label: 'kg' };
const document = {
  id: '7',
  number: 'S7',
  type: 'sale',
  lines: Array.from({ length: 5 }, (_, i) => ({
    id: String(i + 1),
    description: `Item ${i + 1}`,
    unit_snapshot: 'kg',
    qty_milli: '3000',
    returnable_qty_milli: '2000',
    net_base_paisa: '10000',
    tax_paisa: '1300',
  })),
};
const preview = {
  bills: [{ id: '31', number: 'S31', available_paisa: '100000', suggested_paisa: '12525' }],
  unallocated_paisa: '0',
};
const history = [
  {
    id: '21',
    reason: 'Previous sample count',
    status: 'cancelled',
    business_date_bs: 20830103,
    qty_delta_milli: '1000',
  },
];
const responses = new Map<string, unknown>();
export function useData<T>(path: string | null, revision?: number) {
  void revision;
  if (path && !responses.has(path))
    responses.set(path, {
      data: path.includes('/lookup')
        ? lookup
        : path.includes('/contacts/')
          ? party
          : path.includes('/items/')
            ? item
            : path.includes('/document/')
              ? document
              : path.includes('/payments/preview?')
                ? preview
                : history,
      last_page: 1,
    });
  return {
    data: path ? (responses.get(path) as T) : undefined,
    loading: false,
    error: undefined,
    reload: () => {},
  };
}
export function useSave() {
  return {
    busy: false,
    uncertain: false,
    error: undefined,
    clear: () => {},
    retry: () => Promise.reject(new Error('Posting disabled in fixture')),
    save: <T>(path: string, input: Record<string, unknown>, success: (data: T) => void) => {
      void path;
      void input;
      void success;
      throw new Error('Posting disabled in fixture');
    },
  };
}
