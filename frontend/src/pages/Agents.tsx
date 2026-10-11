import { IonButton, IonContent, IonPage } from '@ionic/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { request, send, useData } from '../lib/api';
import type { Business } from '../lib/types';
import { Check, Errors, Field, Heading, Loading, Select, Submit } from '../components/ui';
import { HelpLink } from '../components/Help';

interface AgentToken {
  id: string;
  name: string;
  business_id: string;
  business_name: string | null;
  can_write: boolean;
  last_used_at: string | null;
  expires_at: string | null;
}
const when = (value: string | null) =>
  value ? new Date(value).toLocaleString('en-NP', { timeZone: 'Asia/Kathmandu' }) : 'never';

/** Per-business MCP tokens so AI agents can run daily shop tasks as this user. */
export default function Agents() {
  const [revision, setRevision] = useState(0);
  const tokens = useData<{ data: AgentToken[]; mcp_url: string }>('/api/agent-tokens', revision);
  const businesses = useData<{ data: Business[] }>('/api/businesses');
  const [business, setBusiness] = useState('');
  const [name, setName] = useState('My shop assistant');
  const [write, setWrite] = useState(false);
  const [days, setDays] = useState('90');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<Error>();
  const [created, setCreated] = useState<string>();
  const url = tokens.data?.mcp_url || `${location.origin}/mcp`;
  async function create(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(undefined);
    try {
      const result = await send<{ data: { token: string } }>('/api/agent-tokens', {
        business_id: Number(business),
        name,
        write,
        days: Number(days),
      });
      setCreated(result.data.token);
      setRevision((n) => n + 1);
    } catch (failure) {
      setError(failure as Error);
    } finally {
      setBusy(false);
    }
  }
  return (
    <IonPage>
      <IonContent>
        <div className="businesses-wrap">
          <header className="businesses-header">
            <Link to="/account">My account</Link>
            <Link to="/businesses">Businesses</Link>
            <HelpLink section="agents" />
          </header>
          <Heading
            title="AI agents"
            description="Let an AI assistant (Claude, ChatGPT or any MCP client) look up items and record sales for one business, as you."
          />
          <form className="panel narrow-form section" onSubmit={create}>
            <h2>Create agent access</h2>
            <Errors error={error} />
            <Select label="Business" value={business} onChange={setBusiness}>
              <option value="">Choose business</option>
              {businesses.data?.data.map((row) => (
                <option key={row.id} value={row.id}>
                  {row.name} · {row.role}
                </option>
              ))}
            </Select>
            <Field label="Name" value={name} required onChange={(e) => setName(e.target.value)} />
            <Select label="Expires after" value={days} onChange={setDays}>
              {['7', '30', '90', '365'].map((value) => (
                <option key={value} value={value}>
                  {value} days
                </option>
              ))}
            </Select>
            <Check checked={write} onChange={setWrite}>
              Allow recording sales (otherwise read-only)
            </Check>
            <p>
              The agent gets your role in that business, nothing more. Removing you from the
              business or revoking here stops it immediately.
            </p>
            <Submit busy={busy}>Create token</Submit>
          </form>
          {created && (
            <section className="panel section" role="status">
              <h2>Copy your token now</h2>
              <p>It is shown only once. Treat it like a password.</p>
              <pre className="agent-token">{created}</pre>
              <h3>Claude Code</h3>
              <pre className="agent-token">{`claude mcp add --transport http business-book ${url} --header "Authorization: Bearer ${created}"`}</pre>
              <h3>Other MCP clients (JSON config)</h3>
              <pre className="agent-token">
                {JSON.stringify(
                  {
                    mcpServers: {
                      'business-book': {
                        type: 'http',
                        url,
                        headers: { Authorization: `Bearer ${created}` },
                      },
                    },
                  },
                  null,
                  2,
                )}
              </pre>
              <IonButton fill="outline" onClick={() => setCreated(undefined)}>
                I have copied it
              </IonButton>
            </section>
          )}
          <h2>Your agent tokens</h2>
          {tokens.loading || tokens.error ? (
            <Loading error={tokens.error} retry={tokens.reload} />
          ) : tokens.data?.data.length ? (
            <section className="panel">
              {tokens.data.data.map((token) => (
                <div className="platform-row" key={token.id}>
                  <div>
                    <strong>{token.name}</strong>
                    <small>
                      {token.business_name} · {token.can_write ? 'can record sales' : 'read-only'}
                    </small>
                    <small>
                      Last used {when(token.last_used_at)} · expires {when(token.expires_at)}
                    </small>
                  </div>
                  <IonButton
                    fill="clear"
                    color="danger"
                    onClick={async () => {
                      await request(`/api/agent-tokens/${token.id}`, { method: 'DELETE' });
                      setRevision((n) => n + 1);
                    }}
                  >
                    Revoke
                  </IonButton>
                </div>
              ))}
            </section>
          ) : (
            <p>No agent tokens yet.</p>
          )}
        </div>
      </IonContent>
    </IonPage>
  );
}
