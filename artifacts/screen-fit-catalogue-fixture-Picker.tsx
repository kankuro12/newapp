import { useState } from 'react';
import { IonButton } from '@ionic/react';
import { useData } from '../lib/screen-fit-catalogue-data';
import { useWorkspace } from '../lib/context';
import type { Item, ItemCategory, Lookup, Party } from '../lib/types';
import { currency, format } from '../lib/money';
import { Field, Loading } from './ui';

export default function Picker({
  kind,
  onPick,
  customer,
  role,
  payable = false,
  stockOnly = false,
  contactId,
  priceListId,
  businessDate,
  priceChannel = 'sale',
}: (
  | { kind: 'items' | 'contacts'; onPick: (row: Item | Party) => void }
  | { kind: 'item_categories'; onPick: (row: ItemCategory) => void }
) & {
  customer?: boolean;
  role?: 'employee' | 'rent';
  payable?: boolean;
  stockOnly?: boolean;
  contactId?: string;
  priceListId?: string;
  businessDate?: string;
  priceChannel?: 'sale' | 'purchase';
}) {
  const { base, t } = useWorkspace();
  const partyRole =
    role ||
    (payable ? 'payable' : customer === undefined ? '' : customer ? 'customer' : 'supplier');
  const [query, setQuery] = useState('');
  const [open, setOpen] = useState(false);
  const { data, error, loading } = useData<{ data: Lookup }>(
    open
      ? `${base}/lookup?q=${encodeURIComponent(query)}&party_role=${partyRole}&contact_id=${contactId || ''}&price_channel=${priceChannel}&${priceListId ? 'price_list_id=' + priceListId + '&' : ''}${businessDate ? 'business_date_bs=' + encodeURIComponent(businessDate) + '&' : ''}item_categories=${kind === 'item_categories' ? 1 : 0}`
      : null,
  );
  const rows = (data?.data[kind] || []).filter((row) =>
    kind === 'items' ? !stockOnly || (row as Item).kind === 'stock' : !(row as Party).is_system,
  );
  function choose(row: Item | Party | ItemCategory) {
    if (kind === 'item_categories') onPick(row as ItemCategory);
    else onPick(row as Item | Party);
    setQuery('');
    setOpen(false);
  }
  return (
    <div className="picker">
      <Field
        label={
          kind === 'items'
            ? t('Add item')
            : kind === 'item_categories'
              ? t('Item category')
              : t(
                  role === 'employee'
                    ? 'Employee'
                    : role === 'rent'
                      ? 'Rent payee'
                      : payable || customer === undefined
                        ? 'Party'
                        : customer
                          ? 'Customer'
                          : 'Supplier',
                )
        }
        value={query}
        placeholder={
          kind === 'items'
            ? 'Search item name…'
            : kind === 'item_categories'
              ? t('Search category')
              : 'Search contact name…'
        }
        onFocus={() => setOpen(true)}
        onChange={(e) => {
          setQuery(e.target.value);
          setOpen(true);
        }}
        onKeyDown={(event) => {
          if (['Enter', 'ArrowDown', 'Escape'].includes(event.key)) {
            event.preventDefault();
            event.stopPropagation();
            if (event.key === 'Escape') {
              setOpen(false);
              return;
            }
            if (!open) {
              setOpen(true);
              return;
            }
            const exact = rows.filter((row) => row.name.toLowerCase() === query.toLowerCase());
            if (event.key === 'Enter' && !loading && (exact.length === 1 || rows.length === 1)) {
              choose(exact[0] || rows[0]);
            } else {
              event.currentTarget
                .closest('.picker')
                ?.querySelector<HTMLButtonElement>('.picker-option')
                ?.focus();
            }
          }
        }}
      />
      {open && (
        <div className="picker-results">
          {loading || error ? (
            <Loading error={error} />
          ) : rows.length ? (
            rows.map((row) => (
              <button
                type="button"
                key={row.id}
                className="picker-option"
                onClick={() => choose(row)}
              >
                <strong>{row.name}</strong>
                <small>
                  {kind === 'items'
                    ? `${currency((row as Item).suggested_price_paisa || (row as Item).sale_price_paisa)} · ${(row as Item).kind === 'service' ? 'Service' : format((row as Item).qty_milli, 3) + ' ' + (row as Item).unit_label}`
                    : kind === 'item_categories'
                      ? t('Item category')
                      : (row as Party).phone || 'Contact'}
                </small>
              </button>
            ))
          ) : (
            <p className="subtle">
              No matches. Add{' '}
              {kind === 'items' ? 'item' : kind === 'item_categories' ? 'category' : 'party'} first.
            </p>
          )}
          <IonButton fill="clear" size="small" onClick={() => setOpen(false)}>
            Close search
          </IonButton>
        </div>
      )}
    </div>
  );
}
