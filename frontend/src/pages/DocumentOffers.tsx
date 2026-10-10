import { useState } from 'react';
import { IonButton } from '@ionic/react';
import { Link } from 'react-router-dom';
import { useSave } from '../lib/api';
import { useWorkspace } from '../lib/context';
import { currency } from '../lib/money';
import { Errors, Field, Select, Submit } from '../components/ui';
import type { Doc } from '../lib/types';
import { useReviewedOffer } from '../lib/useReviewedOffer';
export function OfferReview({
  review,
  locked = false,
}: {
  review: ReturnType<typeof useReviewedOffer>;
  locked?: boolean;
}) {
  const { t } = useWorkspace();
  return (
    <div className="offer-review" aria-live="polite">
      <Errors error={review.error} />
      {review.pending && <p>{t('Reviewing offer total…')}</p>}
      {review.preview?.basket_offer && (
        <p>
          <strong>{review.preview.basket_offer.name}</strong>
          <br />
          {t('Before offer (before tax)')}:{' '}
          {currency(
            BigInt(review.preview.total_paisa) -
              BigInt(review.preview.tax_paisa) +
              BigInt(review.preview.basket_offer.discount_paisa),
          )}
          <br />
          {t('Offer saving')}: {currency(review.preview.basket_offer.discount_paisa)}
        </p>
      )}
      <IonButton fill="clear" disabled={locked} onClick={review.recheck}>
        {t('Check total again')}
      </IonButton>
    </div>
  );
}

export function DraftPosting({
  doc,
  accounts,
  form,
  onSaved,
}: {
  doc: Doc;
  accounts: { id: string; name: string }[];
  form: ReturnType<typeof useSave>;
  onSaved: () => void;
}) {
  const { base, path, business, t } = useWorkspace();
  const [paid, setPaid] = useState('0');
  const [account, setAccount] = useState('');
  const locked = form.busy || form.uncertain;
  const selected = !!doc.basket_offer_id;
  const review = useReviewedOffer(
    base + '/document/' + doc.id + '/post/preview',
    { version: doc.version },
    selected,
    locked,
  );
  const blocked = locked || !business.opening_finalized_at || (selected && !review.preview);
  return (
    <form
      className="panel"
      onSubmit={(event) => {
        event.preventDefault();
        if (blocked) return;
        void form.save<Doc>(
          base + '/document/' + doc.id + '/post',
          {
            version: doc.version,
            paid_now: paid,
            money_account_id: account || accounts[0]?.id,
            expected_total_paisa: review.preview?.total_paisa ?? doc.total_paisa,
            ...(selected ? { expected_fingerprint: review.preview?.fingerprint } : {}),
          },
          onSaved,
        );
      }}
    >
      <h2>{t('Finish this draft')}</h2>
      <Link to={path + '/documents/' + doc.type + '/new?draft=' + doc.id}>{t('Edit draft')}</Link>
      <Errors error={form.error} />
      <fieldset disabled={locked} className="entry-lock">
        {selected && <OfferReview review={review} locked={locked} />}
        <Field
          label={t('Paid now')}
          inputMode="decimal"
          value={paid}
          onChange={(e) => setPaid(e.target.value)}
        />
        <Select
          label={t('Payment account')}
          value={account || accounts[0]?.id || ''}
          onChange={setAccount}
        >
          {accounts.map((row) => (
            <option key={row.id} value={row.id}>
              {row.name}
            </option>
          ))}
        </Select>
        <Submit busy={form.busy} disabled={blocked}>
          {t('Post bill')}
        </Submit>
      </fieldset>
      {!business.opening_finalized_at && (
        <Link to={path + '/opening'}>{t('Complete starting balances to post.')}</Link>
      )}
    </form>
  );
}
