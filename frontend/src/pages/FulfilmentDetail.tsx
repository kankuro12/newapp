import { IonButton } from '@ionic/react';
import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useData } from '../lib/api';
import { useWorkspace } from '../lib/context';
import { bsDisplay, format } from '../lib/money';
import type { Fulfilment, Workflow } from '../lib/types';
import { FulfilmentEntry } from '../components/FulfilmentEntry';
import { Heading, Loading, Status } from '../components/ui';

export default function FulfilmentDetail() {
  const { id = '' } = useParams();
  const { base, path, revision, t, changed, business } = useWorkspace();
  const [cancelling, setCancelling] = useState(false);
  const action = useData<{ data: Fulfilment }>(base + '/fulfilment/' + id, revision);
  const order = useData<{ data: Workflow }>(
    action.data ? base + '/workflow/' + action.data.data.workflow_id : null,
    revision,
  );
  if (action.loading || action.error || !action.data)
    return <Loading error={action.error} retry={action.reload} />;
  if (order.loading || order.error || !order.data)
    return <Loading error={order.error} retry={order.reload} />;
  const stage = action.data.data;
  const row = order.data.data;
  const label = stage.source_id
    ? 'Unbilled quantity return'
    : stage.kind === 'receipt'
      ? 'Receipt / work completion'
      : 'Delivery / work completion';
  return (
    <>
      <div className="no-print">
        <Heading title={stage.number + ' · ' + t(label)}>
          <Status value={stage.status} />
          <Link to={path + '/workflow/' + row.id}>
            {t('Source order')} · {row.number}
          </Link>
          <IonButton fill="outline" onClick={() => window.print()}>
            {t('Print quantity slip')}
          </IonButton>
        </Heading>
        {stage.status === 'posted' && business.role !== 'cashier' && !cancelling && (
          <IonButton color="danger" fill="outline" onClick={() => setCancelling(true)}>
            {t('Cancel recorded action')}
          </IonButton>
        )}
        {cancelling && (
          <FulfilmentEntry
            order={row}
            mode="cancel"
            source={stage}
            accounts={[]}
            done={() => {
              setCancelling(false);
              changed();
            }}
          />
        )}
      </div>
      <section className="panel invoice fulfilment-slip">
        <header className="invoice-header">
          <div>
            <span className="eyebrow">{t(label)}</span>
            <h2>{stage.status === 'posted' ? row.business_snapshot.name : business.name}</h2>
          </div>
          <div>
            <strong>{stage.number}</strong>
            <p>{bsDisplay(stage.business_date_bs)} BS</p>
            <Status value={stage.status} />
          </div>
        </header>
        <p>
          {t('Source order')}: {row.number}
        </p>
        {stage.status === 'posted' && (
          <div className="invoice-party">
            <strong>{row.party_snapshot.name}</strong>
            <span>
              {row.party_snapshot.phone} · {row.party_snapshot.address}
            </span>
          </div>
        )}
        {stage.source_id && (
          <p>
            <Link to={path + '/fulfilment/' + stage.source_id}>
              {t('Original quantity record')}
            </Link>
          </p>
        )}
        <div className="invoice-lines">
          {stage.lines.map((line) => (
            <div className="workflow-line" key={line.id}>
              <strong>{line.item_snapshot.description}</strong>
              <span>
                {format(line.qty_milli, 3)} {line.item_snapshot.unit_snapshot}
              </span>
            </div>
          ))}
        </div>
        {stage.reference && (
          <p>
            {t('Reference')}: {stage.reference}
          </p>
        )}
        {stage.notes && <p className="workflow-specifications">{stage.notes}</p>}
        {stage.cancellation_reason && (
          <div className="cancelled-watermark">
            {t('Cancelled')} · {stage.cancellation_reason}
          </div>
        )}
        <p className="delivery-signature">
          {t('Received by / date / signature')}: __________________________
        </p>
        <p className="print-label">
          {t('Quantity record only. Billing and payment are recorded separately.')}
        </p>
      </section>
    </>
  );
}
