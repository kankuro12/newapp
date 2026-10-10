import { IonButton } from '@ionic/react';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useWorkspace } from '../lib/context';
import { bsDisplay, currency, format } from '../lib/money';
import type { Fulfilment, Lookup, Workflow } from '../lib/types';
import { FulfilmentEntry } from './FulfilmentEntry';
import { Status } from './ui';

export function FulfilmentPanel({
  order,
  accounts,
  editing,
}: {
  order: Workflow;
  accounts: Lookup['accounts'];
  editing: (active: boolean) => void;
}) {
  const { path, t, changed } = useWorkspace();
  const navigate = useNavigate();
  const [entry, setEntry] = useState<{ mode: 'record' | 'bill' | 'return'; source?: Fulfilment }>();
  const progress = order.fulfilment;
  if (order.kind === 'quote' || !progress || (order.document_id && !entry)) return null;
  function openEntry(value: { mode: 'record' | 'bill' | 'return'; source?: Fulfilment }) {
    setEntry(value);
    editing(true);
  }
  const open = !['cancelled', 'converted', 'rejected'].includes(order.status);
  const billable = progress.activity.some(
    (stage) =>
      stage.status === 'posted' && stage.lines.some((line) => BigInt(line.billable_qty_milli) > 0n),
  );
  return (
    <section className="no-print fulfilment-panel">
      <h2>{t('Delivery / receipt and billing')}</h2>
      <p>
        {t(
          'Record actual quantities, then bill what is completed. Services follow the same steps.',
        )}
      </p>
      <div className="fulfilment-progress">
        {progress.lines.map((line) => (
          <article className="panel" key={line.position}>
            <h3>{line.description}</h3>
            <small>{line.unit_snapshot}</small>
            <dl>
              {[
                ['Ordered', line.ordered_qty_milli],
                ['Completed', line.completed_qty_milli],
                ['Remaining', line.remaining_qty_milli],
                ['Billed', line.billed_qty_milli],
                ['Ready to bill', line.billable_qty_milli],
                ['Billed returns', line.billed_returned_qty_milli],
              ].map(([label, value]) => (
                <div key={label}>
                  <dt>{t(label)}</dt>
                  <dd>{format(value, 3)}</dd>
                </div>
              ))}
            </dl>
          </article>
        ))}
      </div>
      {!entry && open && (
        <div className="detail-actions">
          {progress.lines.some((line) => BigInt(line.remaining_qty_milli) > 0n) && (
            <IonButton onClick={() => openEntry({ mode: 'record' })}>
              {t(
                order.kind === 'purchase_order'
                  ? 'Receive goods / work'
                  : 'Deliver goods / complete work',
              )}
            </IonButton>
          )}
          {billable && (
            <IonButton onClick={() => openEntry({ mode: 'bill' })}>
              {t('Bill completed quantities')}
            </IonButton>
          )}
        </div>
      )}
      {entry && (
        <FulfilmentEntry
          order={order}
          mode={entry.mode}
          source={entry.source}
          accounts={accounts}
          done={(id) => {
            setEntry(undefined);
            editing(false);
            changed();
            if (id) navigate(path + '/document/' + id);
          }}
        />
      )}
      {!!progress.activity.length && (
        <>
          <h3>{t('Recorded actions')}</h3>
          {progress.activity.map((stage) => (
            <article className="panel fulfilment-history" key={stage.id}>
              <div>
                <Link to={path + '/fulfilment/' + stage.id}>
                  <strong>{stage.number}</strong>
                </Link>{' '}
                · {bsDisplay(stage.business_date_bs)} BS · <Status value={stage.status} />
              </div>
              {stage.lines.map((line) => (
                <p key={line.id}>
                  {line.item_snapshot.description} · {format(line.qty_milli, 3)}{' '}
                  {line.item_snapshot.unit_snapshot}
                </p>
              ))}
              {!entry &&
                open &&
                stage.status === 'posted' &&
                !stage.source_id &&
                stage.lines.some((line) => BigInt(line.returnable_qty_milli) > 0n) && (
                  <IonButton
                    fill="outline"
                    onClick={() => openEntry({ mode: 'return', source: stage })}
                  >
                    {t('Return unbilled quantities')}
                  </IonButton>
                )}
              <Link to={path + '/fulfilment/' + stage.id}>{t('Open quantity slip')}</Link>
            </article>
          ))}
        </>
      )}
      {!!progress.bills.length && (
        <>
          <h3>{t('Linked bills')}</h3>
          {progress.bills.map((bill) => (
            <Link className="record-row" to={path + '/document/' + bill.id} key={bill.id}>
              <strong>{bill.number}</strong>
              <span>{bsDisplay(bill.business_date_bs)} BS</span>
              <strong>{currency(bill.total_paisa)}</strong>
              <Status value={bill.status} />
            </Link>
          ))}
        </>
      )}
    </section>
  );
}
