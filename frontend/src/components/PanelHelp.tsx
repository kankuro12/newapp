import { IonIcon } from '@ionic/react';
import { helpCircleOutline } from 'ionicons/icons';
import { Link } from 'react-router-dom';
import { panelGuides } from '../lib/panelHelp';

/**
 * Collapsed "How to use this screen" card. Pass `path` inside the business workspace so the
 * full chapter opens in the workspace manual; outside it, the chapter opens in a new tab.
 */
export function PanelHelp({
  guide,
  path,
  label = 'How to use this screen',
}: {
  guide?: string;
  path?: string;
  label?: string;
}) {
  const item = guide ? panelGuides[guide] : undefined;
  if (!item) return null;
  const chapter = path ? (
    <Link className="panel-help-link" to={`${path}/help?section=${item.section}`}>
      Open full chapter
    </Link>
  ) : (
    <a
      className="panel-help-link"
      href={`/help/user-manual.html#${item.section}`}
      target="_blank"
      rel="noopener"
    >
      Open full chapter
    </a>
  );
  return (
    <details className="panel-help">
      <summary>
        <IonIcon icon={helpCircleOutline} aria-hidden="true" />
        <span>{label}</span>
      </summary>
      <div className="panel-help-body">
        <p>{item.summary}</p>
        <ol>
          {item.steps.map((step) => (
            <li key={step}>{step}</li>
          ))}
        </ol>
        {item.tips?.length ? (
          <>
            <strong>Good to know</strong>
            <ul className="panel-help-tips">
              {item.tips.map((tip) => (
                <li key={tip}>{tip}</li>
              ))}
            </ul>
          </>
        ) : null}
        {chapter}
      </div>
    </details>
  );
}
