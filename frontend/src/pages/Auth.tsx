import { tenantSignOut } from '../lib/push';
import { IonButton, IonIcon, IonPage, IonContent } from '@ionic/react';
import {
  arrowForwardOutline,
  checkmarkOutline,
  leafOutline,
  lockClosedOutline,
} from 'ionicons/icons';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useState } from 'react';
import { send, resetSession } from '../lib/api';
import { Errors, Field, Select, Submit } from '../components/ui';
import type { User } from '../lib/types';
import callingCodes from '../../../backend/resources/country-calling-codes.json';

const signupCountries = [...callingCodes.countries].sort((a, b) => a.name.localeCompare(b.name));

export default function Auth({ reload, admin = false }: { reload: () => void; admin?: boolean }) {
  const location = useLocation();
  const navigate = useNavigate();
  const register = location.pathname === '/signup';
  const forgot = location.pathname === '/recover';
  const reset = location.pathname === '/reset';
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [country, setCountry] = useState('NP');
  const [email, setEmail] = useState(new URLSearchParams(location.search).get('email') || '');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<Error>();
  const [message, setMessage] = useState('');
  const title = admin
    ? 'Platform sign in'
    : register
      ? 'Create your account.'
      : forgot
        ? 'Reset your password.'
        : reset
          ? 'Choose a new password.'
          : 'Your business, in good order.';
  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(undefined);
    try {
      resetSession();
      await send(
        admin
          ? '/platform-auth/login'
          : register
            ? '/register'
            : forgot
              ? '/forgot-password'
              : reset
                ? '/reset-password'
                : '/login',
        {
          name,
          email,
          ...(register ? { phone, country_code: country } : {}),
          password,
          password_confirmation: confirmation,
          token: new URLSearchParams(location.search).get('token'),
        },
      );
      if (forgot) setMessage('Reset link sent. Check your email.');
      else if (reset) {
        setMessage('Password reset. You can sign in now.');
        navigate('/signin');
      } else {
        reload();
        navigate(admin ? '/platform' : '/businesses');
      }
    } catch (error) {
      setError(error as Error);
    } finally {
      setBusy(false);
    }
  }
  return (
    <IonPage>
      <IonContent>
        <div className="auth-layout">
          <section className="auth-story">
            <Link to="/signin" className="brand">
              <span className="brand-mark">b</span>
              <span>
                business<span className="brand-book">book</span>
              </span>
            </Link>
            <div>
              <span className="eyebrow">LESS PAPER. MORE PEACE OF MIND.</span>
              <h1>
                Small business.
                <br />
                Big clarity.
              </h1>
              <p>A simple place for sales, stock, and the money that matters.</p>
              <div className="story-checks">
                {['Sell in seconds', 'Know who owes you', 'Keep every business separate'].map(
                  (text) => (
                    <span key={text}>
                      <IonIcon icon={checkmarkOutline} />
                      {text}
                    </span>
                  ),
                )}
              </div>
            </div>
            <div className="story-note">
              <IonIcon icon={leafOutline} />
              <span>
                Made for everyday business in Nepal.
                <br />
                One app, on your phone and your computer.
              </span>
            </div>
          </section>
          <section className="auth-form-wrap">
            <div className="auth-form">
              <span className="eyebrow">
                {admin ? 'SUPER ADMIN' : register ? 'LET’S GET STARTED' : 'WELCOME BACK'}
              </span>
              <h2>{title}</h2>
              <p>
                {admin
                  ? 'Separate platform account. Business finances remain private.'
                  : register
                    ? 'Start your 14-day trial.'
                    : 'Sign in to your Business Book.'}
              </p>
              <form onSubmit={submit}>
                <Errors error={error} />
                {message && (
                  <div className="notice" role="status">
                    {message}
                  </div>
                )}
                {register && (
                  <Field
                    label="Your name"
                    name="name"
                    autoComplete="name"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    required
                  />
                )}
                {register && (
                  <div className="signup-phone-row">
                    <Select
                      label="Country"
                      name="country_code"
                      value={country}
                      onChange={setCountry}
                    >
                      {signupCountries.map((row) => (
                        <option key={row.code} value={row.code}>
                          {row.name} (+{row.calling_code})
                        </option>
                      ))}
                    </Select>
                    <Field
                      label="Phone number"
                      name="phone"
                      type="tel"
                      inputMode="tel"
                      autoComplete="tel-national"
                      value={phone}
                      onChange={(e) => setPhone(e.target.value)}
                      placeholder="9801234567"
                      maxLength={30}
                      required
                    />
                  </div>
                )}
                {register && <p className="subtle">Enter phone number without country code.</p>}
                <Field
                  label="Email"
                  name="email"
                  type="email"
                  autoComplete="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  required
                />
                <div className={register ? 'signup-password-row' : undefined}>
                  {!forgot && (
                    <Field
                      label="Password"
                      name="password"
                      type="password"
                      autoComplete={register || reset ? 'new-password' : 'current-password'}
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      minLength={register || reset ? 8 : undefined}
                      required
                    />
                  )}
                  {(register || reset) && (
                    <Field
                      label="Confirm password"
                      type="password"
                      autoComplete="new-password"
                      value={confirmation}
                      onChange={(e) => setConfirmation(e.target.value)}
                      required
                    />
                  )}
                </div>
                {!admin && !register && !forgot && !reset && (
                  <Link className="form-link" to="/recover">
                    Forgot password?
                  </Link>
                )}
                <Submit busy={busy}>
                  {register
                    ? 'Create account'
                    : forgot
                      ? 'Send reset link'
                      : reset
                        ? 'Reset password'
                        : 'Sign in'}
                </Submit>
              </form>
              {!admin && (
                <p className="auth-footer">
                  {register ? 'Already have an account?' : 'New to Business Book?'}{' '}
                  <Link to={register ? '/signin' : '/signup'}>
                    {register ? 'Sign in' : 'Create an account'}{' '}
                    <IonIcon icon={arrowForwardOutline} />
                  </Link>
                </p>
              )}
              <div className="privacy-note">
                <IonIcon icon={lockClosedOutline} />
                Secure sign in. Your records stay yours.
              </div>
              {admin ? (
                <Link to="/signin">Business sign in</Link>
              ) : (
                <Link className="subtle" to="/platform/signin">
                  Platform administrator
                </Link>
              )}
            </div>
          </section>
        </div>
      </IonContent>
    </IonPage>
  );
}

export function Verify({ user, reload }: { user: User; reload: () => void }) {
  const [message, setMessage] = useState('');
  const [error, setError] = useState<Error>();
  return (
    <IonPage>
      <IonContent>
        <div className="center-screen">
          <div className="panel verify-card">
            <span className="brand-mark">b</span>
            <h1>Check your email</h1>
            <p>
              Verify <strong>{user.email}</strong> to open your business.
            </p>
            <Errors error={error} />
            {message && <p role="status">{message}</p>}
            <IonButton
              onClick={async () => {
                try {
                  await send('/email/verification-notification', {});
                  setMessage('Verification link sent.');
                } catch (e) {
                  setError(e as Error);
                }
              }}
            >
              Resend verification link
            </IonButton>
            <IonButton fill="outline" onClick={reload}>
              I’ve verified my email
            </IonButton>
            <IonButton
              fill="clear"
              onClick={async () => {
                await tenantSignOut(user.id);
                resetSession();
                reload();
              }}
            >
              Sign out
            </IonButton>
          </div>
        </div>
      </IonContent>
    </IonPage>
  );
}

export function AccountPage({ user, reload }: { user: User; reload: () => void }) {
  const [name, setName] = useState(user.name);
  const [email, setEmail] = useState(user.email);
  const [current, setCurrent] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [error, setError] = useState<Error>();
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  async function update(path: string, input: Record<string, string>) {
    setBusy(true);
    setError(undefined);
    setMessage('');
    try {
      await send(path, input, 'PUT');
      setCurrent('');
      setPassword('');
      setConfirmation('');
      setMessage('Account updated.');
      reload();
    } catch (e) {
      setError(e as Error);
    } finally {
      setBusy(false);
    }
  }
  return (
    <IonPage>
      <IonContent>
        <div className="businesses-wrap">
          <Link to="/businesses">Back to businesses</Link>
          <h1>My account</h1>
          <Link to="/notifications">Notifications</Link>
          <Errors error={error} />
          {message && <p role="status">{message}</p>}
          <form
            className="panel narrow-form"
            onSubmit={(e) => {
              e.preventDefault();
              void update('/user/profile-information', { name, email });
            }}
          >
            <h2>Your details</h2>
            <Field
              label="Your name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
            />
            <Field
              label="Email"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
            <Submit busy={busy}>Update details</Submit>
          </form>
          <form
            className="panel narrow-form section"
            onSubmit={(e) => {
              e.preventDefault();
              void update('/user/password', {
                current_password: current,
                password,
                password_confirmation: confirmation,
              });
            }}
          >
            <h2>Change password</h2>
            <Field
              label="Current password"
              type="password"
              autoComplete="current-password"
              value={current}
              onChange={(e) => setCurrent(e.target.value)}
              required
            />
            <Field
              label="New password"
              type="password"
              autoComplete="new-password"
              value={password}
              minLength={8}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
            <Field
              label="Confirm new password"
              type="password"
              autoComplete="new-password"
              value={confirmation}
              onChange={(e) => setConfirmation(e.target.value)}
              required
            />
            <Submit busy={busy}>Update password</Submit>
          </form>
        </div>
      </IonContent>
    </IonPage>
  );
}
