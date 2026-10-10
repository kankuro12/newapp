import { createRoot } from 'react-dom/client';
import { IonApp, IonContent, IonIcon, IonPage, setupIonicReact } from '@ionic/react';
import { MemoryRouter, Link } from 'react-router-dom';
import {
  homeOutline,
  storefrontOutline,
  receiptOutline,
  peopleOutline,
  gridOutline,
} from 'ionicons/icons';
import { Context } from './lib/context';
import { translator } from './lib/i18n';
import type { Business } from './lib/types';
import { Route, Routes } from 'react-router-dom';
import { MoneyForm, ReturnForm, CountForm } from './pages/DailyForms.screen-fit';

import '@ionic/react/css/core.css';
import '@ionic/react/css/normalize.css';
import '@ionic/react/css/structure.css';
import '@ionic/react/css/typography.css';
import './theme/variables.css';
import './theme/app.css';
setupIonicReact();
const params = new URLSearchParams(location.search);
const locale = params.get('locale') === 'ne' ? 'ne' : 'en';
const t = translator(locale);
const business = {
  role: params.get('role') || 'owner',
  opening_finalized_at: params.get('opening') === '1' ? null : '2026-10-04',
} as Business;
const path = '/app/fixture';
createRoot(document.getElementById('root')!).render(
  <IonApp>
    <MemoryRouter
      initialEntries={[
        params.get('screen') === 'return'
          ? '/document/7/return'
          : params.get('screen') === 'count'
            ? '/count?item=5'
            : '/money/' + (params.get('kind') || 'receipt') + '/new?contact=9&amount=125.25',
      ]}
    >
      <Context.Provider
        value={{
          business,
          base: '/api/fixture',
          path,
          today: 20830103,
          revision: 0,
          changed: () => {},
          t,
          locale,
        }}
      >
        <IonPage>
          <IonContent>
            <div className="app-frame">
              <aside className="sidebar">
                <div className="brand">business book</div>
              </aside>
              <div className="main-wrap">
                <header className="topbar">
                  <div className="topbar-business">
                    <span className="mobile-logo brand-mark">b</span>
                    <strong>Screen fit fixture</strong>
                  </div>
                  <div className="topbar-actions">
                    <button className="locale-button">
                      {locale === 'en' ? 'नेपाली' : 'English'}
                    </button>
                    <button className="avatar" aria-label="Fixture user">
                      F
                    </button>
                  </div>
                </header>
                <main className="page-wrap">
                  <Routes>
                    <Route path="/money/:kind/new" element={<MoneyForm />} />
                    <Route path="/document/:id/return" element={<ReturnForm />} />
                    <Route path="/count" element={<CountForm />} />
                  </Routes>
                </main>
                <footer className="app-footer">
                  <span>Business Book · simple bookkeeping</span>
                  <span>NPR · {business.role}</span>
                </footer>
              </div>
              <nav className="bottom-nav">
                {[
                  ['', 'Home', homeOutline],
                  ['/pos', 'POS', storefrontOutline],
                  ['/sales', 'Sales', receiptOutline],
                  ['/contacts', 'Parties', peopleOutline],
                  ['/more', 'More', gridOutline],
                ].map(([suffix, label, icon]) => (
                  <Link to={path + suffix} key={label}>
                    <IonIcon icon={icon} />
                    <span>{t(label)}</span>
                  </Link>
                ))}
              </nav>
            </div>
          </IonContent>
        </IonPage>
      </Context.Provider>
    </MemoryRouter>
  </IonApp>,
);
