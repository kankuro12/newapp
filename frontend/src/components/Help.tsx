import { IonIcon } from '@ionic/react';
import { helpCircleOutline } from 'ionicons/icons';
import { Link } from 'react-router-dom';
import { manualUrl } from '../lib/manual';

/** Opens the manual at a section: inside the workspace shell when a path is given, otherwise in a new tab. */
export function HelpLink({
  section,
  path,
  label = 'Help',
}: {
  section: string;
  path?: string;
  label?: string;
}) {
  const content = (
    <>
      <IonIcon icon={helpCircleOutline} aria-hidden="true" />
      <span>{label}</span>
    </>
  );
  return path ? (
    <Link className="help-link" to={`${path}/help?section=${section}`} title={`Manual: ${section}`}>
      {content}
    </Link>
  ) : (
    <a
      className="help-link"
      href={manualUrl(section)}
      target="_blank"
      rel="noopener"
      title={`Manual: ${section}`}
    >
      {content}
    </a>
  );
}
