import { Link } from 'react-router-dom';
import { currency } from '../lib/money';
import type { SourceOrderSnapshot } from '../lib/types';

export function SourceOrderAllocation({
  source,
  path,
  discountPaisa,
  t,
}: {
  source?: SourceOrderSnapshot | null;
  path: string;
  discountPaisa: string;
  t: (text: string) => string;
}) {
  if (!source) return null;
  const returned = source.allocation === 'cumulative_source_bill_return';
  const differences = source.lines.filter(
    (line) =>
      BigInt(line.gross_rounding_paisa) ||
      BigInt(line.tax_rounding_paisa) ||
      BigInt(line.cost_variance_paisa ?? '0'),
  );
  return (
    <section className="source-allocation-note">
      <p>
        <strong>
          {t(returned ? 'Original bill return allocation' : 'Source order allocation')}
        </strong>{' '}
        · <Link to={`${path}/workflow/${source.id}`}>{source.number}</Link>
        {source.source_bill_id && (
          <>
            {' '}
            · <Link to={`${path}/document/${source.source_bill_id}`}>{t('Original bill')}</Link>
          </>
        )}
      </p>
      <p>
        {t(
          'Original agreed amounts are allocated to these quantities. Original rates stay fixed; rounding can differ from a new bill.',
        )}
      </p>
      {source.offer && (
        <p>
          {t('Agreed offer')}: {source.offer.name} · {t('Allocated source discounts')}:{' '}
          <strong>{currency(discountPaisa)}</strong> {t('(includes original line discounts)')}
        </p>
      )}
      {differences.map((line) => (
        <div key={line.position}>
          <strong>
            {t('Line')} {line.position}
          </strong>
          {BigInt(line.gross_rounding_paisa) !== 0n && (
            <p>
              {t('Price allocation difference')}: {currency(line.gross_rounding_paisa)}
            </p>
          )}
          {BigInt(line.tax_rounding_paisa) !== 0n && (
            <p>
              {t('Tax allocation difference')}: {currency(line.tax_rounding_paisa)}
            </p>
          )}
          {BigInt(line.cost_variance_paisa ?? '0') !== 0n && (
            <p>
              {t('Receipt cost difference')}: {currency(line.cost_variance_paisa)}
            </p>
          )}
        </div>
      ))}
    </section>
  );
}
