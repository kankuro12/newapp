import { IonButton, IonIcon, IonSpinner } from '@ionic/react';
import {
  addOutline,
  arrowBackOutline,
  arrowForwardOutline,
  checkmarkCircleOutline,
  receiptOutline,
} from 'ionicons/icons';
import { Children, cloneElement, isValidElement, useEffect, useState } from 'react';
import type { ChangeEvent, InputHTMLAttributes, ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { Link } from 'react-router-dom';
import { ApiError, useOnline } from '../lib/api';

export function Field({
  label,
  error,
  children,
  ...input
}: InputHTMLAttributes<HTMLInputElement> & {
  label: string;
  error?: string[];
  children?: ReactNode;
}) {
  return (
    <label className={`field ${error ? 'invalid' : ''}`}>
      <span>{label}</span>
      {children || (
        <input
          {...input}
          onInput={
            input.onInput ||
            (input.type === 'time'
              ? (event) => input.onChange?.(event as ChangeEvent<HTMLInputElement>)
              : undefined)
          }
          aria-invalid={!!error}
        />
      )}
      {error && <small role="alert">{error.join(' ')}</small>}
    </label>
  );
}
export function Select({
  label,
  value,
  onChange,
  children,
  name,
  error,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  children: ReactNode;
  name?: string;
  error?: string[];
}) {
  return (
    <Field label={label} error={error}>
      <select
        name={name}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        aria-invalid={!!error}
      >
        {children}
      </select>
    </Field>
  );
}
export function Check({
  children,
  checked,
  onChange,
  disabled = false,
}: {
  children: ReactNode;
  checked: boolean;
  disabled?: boolean;
  onChange: (value: boolean) => void;
}) {
  return (
    <label className="check">
      <input
        type="checkbox"
        disabled={disabled}
        checked={checked}
        onChange={(event) => onChange(event.target.checked)}
      />
      <span>{children}</span>
    </label>
  );
}
export function OptionalDetails({ label, children }: { label: string; children: ReactNode }) {
  return (
    <details
      className="optional-details"
      onInvalidCapture={(event) => {
        event.currentTarget.open = true;
      }}
    >
      <summary>{label}</summary>
      <div>{children}</div>
    </details>
  );
}
export function Errors({ error }: { error?: Error }) {
  return error ? (
    <div className="error-box" role="alert">
      <strong>{error.message}</strong>
      {error instanceof ApiError &&
        Object.entries(error.errors).map(([key, messages]) => (
          <p key={key}>{messages.join(' ')}</p>
        ))}
    </div>
  ) : null;
}
export function Loading({ error, retry }: { error?: Error; retry?: () => void }) {
  return error ? (
    <div className="panel">
      <Errors error={error} />
      {retry && (
        <IonButton fill="outline" onClick={retry}>
          Retry
        </IonButton>
      )}
    </div>
  ) : (
    <div className="loading">
      <IonSpinner name="crescent" />
      <span>Loading…</span>
    </div>
  );
}
export function Empty({
  title = 'No entries yet',
  description,
  action,
  onClick,
}: {
  title?: string;
  description?: string;
  action?: string;
  onClick?: () => void;
}) {
  return (
    <div className="empty">
      <span className="empty-icon">
        <IonIcon icon={receiptOutline} />
      </span>
      <h3>{title}</h3>
      {description && <p>{description}</p>}
      {action && (
        <IonButton onClick={onClick}>
          <IonIcon slot="start" icon={addOutline} />
          {action}
        </IonButton>
      )}
    </div>
  );
}
export function Status({ value }: { value: string }) {
  return <span className={`badge ${value}`}>{value.replaceAll('_', ' ')}</span>;
}
export function Heading({
  eyebrow,
  title,
  children,
  description,
}: {
  eyebrow?: string;
  title: string;
  children?: ReactNode;
  description?: string;
}) {
  const [mobile, setMobile] = useState(() => window.matchMedia('(max-width: 767px)').matches);
  const [backSlot, setBackSlot] = useState<HTMLElement | null>(null);
  useEffect(() => {
    const query = window.matchMedia('(max-width: 767px)');
    const update = () => setMobile(query.matches);
    setBackSlot(document.querySelector<HTMLElement>('.topbar .mobile-back-slot'));
    query.addEventListener?.('change', update);
    return () => query.removeEventListener?.('change', update);
  }, []);
  const actions = Children.toArray(children);
  const back = actions.find(
    (action) =>
      isValidElement<{ children?: ReactNode }>(action) &&
      action.type === Link &&
      ['Back', 'पछाडि'].includes(String(action.props.children)),
  );
  const movingBack =
    mobile &&
    backSlot &&
    isValidElement<{
      children?: ReactNode;
      className?: string;
      'aria-label'?: string;
      title?: string;
    }>(back);
  return (
    <div className="page-heading">
      <div>
        {eyebrow && <span className="eyebrow">{eyebrow}</span>}
        <h1>{title}</h1>
        {description && <p>{description}</p>}
      </div>
      <div className="heading-actions">
        {movingBack ? actions.filter((action) => action !== back) : children}
      </div>
      {movingBack &&
        createPortal(
          cloneElement(back, {
            className: [back.props.className, 'mobile-topbar-back'].filter(Boolean).join(' '),
            'aria-label': String(back.props.children),
            title: String(back.props.children),
            children: <IonIcon icon={arrowBackOutline} aria-hidden="true" />,
          }),
          backSlot,
        )}
    </div>
  );
}
export function Submit({
  busy,
  children,
  disabled,
}: {
  busy: boolean;
  children: ReactNode;
  disabled?: boolean;
}) {
  const online = useOnline();
  return (
    <IonButton type="submit" disabled={busy || disabled || !online}>
      {busy ? (
        <IonSpinner name="crescent" />
      ) : (
        <>
          <span>{children}</span>
          <IonIcon slot="end" icon={arrowForwardOutline} />
        </>
      )}
    </IonButton>
  );
}
export function Notice({ children }: { children: ReactNode }) {
  return (
    <div className="notice">
      <IonIcon icon={checkmarkCircleOutline} />
      <span>{children}</span>
    </div>
  );
}
