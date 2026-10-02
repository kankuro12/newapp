import { IonButton, IonContent, IonIcon, IonPage } from '@ionic/react';
import { addOutline, arrowForwardOutline, storefrontOutline } from 'ionicons/icons';
import { Link, useNavigate } from 'react-router-dom';
import { useState } from 'react';
import { useData, request, resetSession, send, useSave } from '../lib/api';
import type { Business, User } from '../lib/types';
import { Errors, Field, Heading, Loading, Status, Submit } from '../components/ui';

export default function Businesses({ user, logout }: { user: User; logout: () => void }) {
  const { data, loading, error, reload } = useData<{ data: Business[] }>('/api/businesses'); const [create, setCreate] = useState(false); const [name, setName] = useState(''); const [address, setAddress] = useState(''); const navigate = useNavigate(); const form = useSave();
  return <IonPage><IonContent><div className="businesses-wrap"><header className="businesses-header"><span className="brand"><span className="brand-mark">b</span>businessbook</span><Link to="/account">My account</Link><IonButton fill="clear" onClick={async () => { await request('/logout', { method: 'POST' }); resetSession(); logout(); }}>Sign out</IonButton></header><Heading eyebrow={`HELLO, ${user.name.toUpperCase()}`} title="Choose your business" description="A separate book for every business."><IonButton onClick={() => setCreate(true)}><IonIcon slot="start" icon={addOutline} />New business</IonButton></Heading>{loading ? <Loading /> : error ? <Loading error={error} retry={reload} /> : <div className="business-grid">{data?.data.map(business => <Link className="business-card panel" to={`/app/${business.slug}`} key={business.id}><span className="business-symbol"><IonIcon icon={storefrontOutline} /></span><div><h2>{business.name}</h2><span className="subtle">{business.role} · <Status value={business.access_status} /></span></div><IonIcon icon={arrowForwardOutline} /></Link>)}</div>}{(create || data?.data.length === 0) && <form className="panel business-create" onSubmit={e => { e.preventDefault(); void form.save<Business>('/api/businesses', { name, address }, business => navigate(`/app/${business.slug}/opening`)); }}><h2>Start your business book</h2><p>We’ll set up cash, bank, and common expenses for you.</p><Errors error={form.error} /><Field label="Business name" name="name" value={name} onChange={e => setName(e.target.value)} required maxLength={150} /><Field label="Address" value={address} onChange={e => setAddress(e.target.value)} /><Submit busy={form.busy}>Create business</Submit></form>}</div></IonContent></IonPage>;
}

export function Invitation({ token }: { token: string }) {
  const navigate = useNavigate(); const [error, setError] = useState<Error>(); const [busy, setBusy] = useState(false);
  return <IonPage><IonContent><div className="center-screen"><div className="panel"><h1>Join your business</h1><p>Your verified email must match the invitation.</p><Errors error={error} /><IonButton disabled={busy} onClick={async () => { setBusy(true); try { const response = await send<{ data: Business }>(`/api/invitations/${encodeURIComponent(token)}`, {}); navigate(`/app/${response.data.slug}`); } catch (e) { setError(e as Error); } finally { setBusy(false); } }}>Accept invitation</IonButton></div></div></IonContent></IonPage>;
}
