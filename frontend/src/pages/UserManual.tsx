import { Link, useSearchParams } from 'react-router-dom';
import { Heading } from '../components/ui';
import { manualUrl } from '../lib/manual';
import { useWorkspace } from '../lib/context';

export default function UserManual() {
  const { path, t } = useWorkspace();
  const [params] = useSearchParams();
  const section = /^[a-z-]{2,40}$/.test(params.get('section') || '')
    ? params.get('section')!
    : 'contents';
  return (
    <>
      <Heading title={t('User manual')}>
        <Link to={`${path}/more`}>{t('Back')}</Link>
        <a href={manualUrl(section)} target="_blank" rel="noopener">
          {t('Open full manual')}
        </a>
      </Heading>
      <iframe
        key={section}
        className="manual-frame"
        title={t('User manual')}
        src={manualUrl(section)}
      />
    </>
  );
}
