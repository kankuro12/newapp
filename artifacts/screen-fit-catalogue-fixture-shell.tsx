import { createRoot } from 'react-dom/client';
import { IonApp, IonContent, IonIcon, IonPage, setupIonicReact } from '@ionic/react';
import { MemoryRouter, Link, Route, Routes } from 'react-router-dom';
import {
  homeOutline,
  storefrontOutline,
  receiptOutline,
  peopleOutline,
  gridOutline,
} from 'ionicons/icons';
import { Context } from './lib/context';
import { translator } from './lib/i18n';
import type { Business, User } from './lib/types';
import { MasterForm } from './pages/Masters.screen-fit';
import Reports from './pages/Reports.screen-fit';
import Businesses from './pages/Businesses.screen-fit';
import '@ionic/react/css/core.css';
import '@ionic/react/css/normalize.css';
import '@ionic/react/css/structure.css';
import '@ionic/react/css/typography.css';
import './theme/variables.css';
import './theme/app.css';
setupIonicReact();
const params = new URLSearchParams(location.search);
const screen = params.get('screen') || 'product';
const locale = params.get('locale') === 'ne' ? 'ne' : 'en';
const t = translator(locale);
const business = { role: 'owner', pos_profile: 'glass' } as Business;
const path = '/app/fixture';
createRoot(document.getElementById('root')!).render(
  <IonApp>
    <MemoryRouter
      initialEntries={[
        screen === 'reports'
          ? '/reports?report=' + (params.get('report') || 'overview')
          : params.get('edit') === '1'
            ? '/edit/7'
            : '/new',
      ]}
    >
      {screen === 'businesses' ? (
        <Businesses user={{ name: 'Sample owner', locale } as User} logout={() => {}} />
      ) : (
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
                      <Route path="/reports" element={<Reports />} />
                      <Route
                        path="/new"
                        element={
                          <MasterForm resource={screen === 'contact' ? 'contacts' : 'items'} />
                        }
                      />
                      <Route
                        path="/edit/:id"
                        element={
                          <MasterForm resource={screen === 'contact' ? 'contacts' : 'items'} />
                        }
                      />
                    </Routes>
                  </main>
                  <footer className="app-footer">
                    <span>Business Book · simple bookkeeping</span>
                    <span>NPR · owner</span>
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
      )}
    </MemoryRouter>
  </IonApp>,
);
