import { Link } from 'react-router-dom';
import { Heading } from '../components/ui';
import { useWorkspace } from '../lib/context';

export default function UserManual() {
  const {path,t}=useWorkspace();
  return <>
    <Heading title={t('User manual')}><Link to={`${path}/more`}>{t('Back')}</Link></Heading>
    <iframe className="manual-frame" title={t('User manual')} src="/help/user-manual.html" />
  </>;
}
