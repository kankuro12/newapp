import { Navigate, Route, Routes, useParams, useLocation } from 'react-router-dom';
import { IonApp, IonContent, IonPage, setupIonicReact } from '@ionic/react';
import { IonReactRouter } from '@ionic/react-router';
import { ApiError, useData } from './lib/api';
import type { User } from './lib/types';
import Auth, { Verify, AccountPage } from './pages/Auth';
import Businesses, { Invitation } from './pages/Businesses';
import Workspace from './pages/Workspace';
import Platform from './pages/Platform';
import { Loading } from './components/ui';

/* Core CSS required for Ionic components to work properly */
import '@ionic/react/css/core.css';

/* Basic CSS for apps built with Ionic */
import '@ionic/react/css/normalize.css';
import '@ionic/react/css/structure.css';
import '@ionic/react/css/typography.css';

/* Theme variables */
import './theme/variables.css';
import './theme/app.css';

setupIonicReact();

function TenantApp() {
  const session = useData<{ data: User; today_bs: number }>('/api/me');
  const location = useLocation();
  if (session.loading) return <IonPage><IonContent><Loading /></IonContent></IonPage>;
  if (!session.data) return session.error && (!(session.error instanceof ApiError) || session.error.status !== 401) ? <IonPage><IonContent><Loading error={session.error} retry={session.reload} /></IonContent></IonPage> : <Auth reload={session.reload} />;
  if (['/reset','/recover'].includes(location.pathname)) return <Auth reload={session.reload} />;
  if (location.pathname === '/account') return <AccountPage user={session.data.data} reload={session.reload} />;
  if (!session.data.data.email_verified_at) return <Verify user={session.data.data} reload={session.reload} />;
  return <Routes><Route path="/businesses" element={<Businesses user={session.data.data} logout={session.reload} />} /><Route path="/app/:slug/*" element={<Workspace user={session.data.data} today={session.data.today_bs} logout={session.reload} />} /><Route path="/invite/:token" element={<Invite />} /><Route path="*" element={<Navigate to="/businesses" replace />} /></Routes>;
}
function Invite() { const { token = '' } = useParams(); return <Invitation token={token} />; }
const App: React.FC = () => (
  <IonApp>
    <IonReactRouter>
      <Routes><Route path="/platform/*" element={<Platform />} /><Route path="*" element={<TenantApp />} /></Routes>
    </IonReactRouter>
  </IonApp>
);

export default App;
