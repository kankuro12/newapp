import { useEffect, useRef, useState } from 'react';
import type { FormEventHandler, ReactNode } from 'react';
import { ApiError } from '../lib/api';

export default function EntrySteps({ children, labels, onSubmit, dirty = false, busy = false, error, canContinue = [], total, t = value => value }: {
  labels: string[]; children: ReactNode; onSubmit: FormEventHandler<HTMLFormElement>; dirty?: boolean; busy?: boolean; error?: Error; canContinue?: boolean[]; total?: string; t?: (value: string) => string;
}) {
  const [step, setStep] = useState(0);
  const [mobile, setMobile] = useState(() => window.matchMedia('(max-width: 767px)').matches);
  const form = useRef<HTMLFormElement>(null);
  useEffect(() => {
    const query = window.matchMedia('(max-width: 767px)');
    const update = () => setMobile(query.matches);
    query.addEventListener?.('change', update);
    return () => query.removeEventListener?.('change', update);
  }, []);
  useEffect(() => {
    if (!(error instanceof ApiError)) return;
    const key = Object.keys(error.errors)[0];
    const field = key && form.current?.elements.namedItem(key);
    if (field instanceof HTMLElement) {
      const section = field.closest<HTMLElement>('[data-entry-step]');
      if (section) setStep(Number(section.dataset.entryStep));
      let ancestor = field.parentElement;
      while (ancestor) { if (ancestor instanceof HTMLDetailsElement) ancestor.open = true; ancestor = ancestor.parentElement; }
      requestAnimationFrame(() => field.focus());
    }
  }, [error]);
  function move(next: number) {
    if (busy) return;
    if (next > step) {
      for (let index = step; index < next; index++) {
        if (canContinue[index] === false) return;
        const fields = form.current?.querySelectorAll<HTMLInputElement | HTMLSelectElement>(`[data-entry-step="${index}"] input, [data-entry-step="${index}"] select`);
        for (const field of fields || []) { if (!field.reportValidity()) return; }
      }
    }
    setStep(next);
    requestAnimationFrame(() => {
      const heading = form.current?.querySelector<HTMLElement>(`[data-entry-step="${next}"] h2`);
      if (heading) { heading.tabIndex = -1; heading.focus(); heading.scrollIntoView?.({ block: 'start' }); }
    });
  }
  return <form ref={form} className={`document-form entry-form entry-step-${step}`} data-entry-mobile={mobile} data-dirty={dirty ? 'true' : 'false'} onSubmit={event => {
    if (mobile && step < labels.length - 1) { event.preventDefault(); move(step + 1); }
    else onSubmit(event);
  }} onKeyDown={event => {
    if (event.key === 'Enter' && !event.defaultPrevented && mobile && step < labels.length - 1 && event.target instanceof HTMLInputElement) { event.preventDefault(); move(step + 1); }
  }} onInvalidCapture={event => {
    const field = event.target as HTMLElement;
    let ancestor = field.parentElement;
    while (ancestor) { if (ancestor instanceof HTMLDetailsElement) ancestor.open = true; ancestor = ancestor.parentElement; }
    const section = field.closest<HTMLElement>('[data-entry-step]');
    if (section && mobile) { event.preventDefault(); setStep(Number(section.dataset.entryStep)); requestAnimationFrame(() => field.focus()); }
  }}>
    <nav className="entry-progress" aria-label={t('Entry steps')}>{labels.map((label, index) => <button key={label} type="button" disabled={busy} aria-current={index === step ? 'step' : undefined} onClick={() => move(index)}>{index + 1} {label}</button>)}</nav>
    {children}
    <div className="entry-step-actions">
      {total && <div className="entry-running-total"><span>{t('Total')}</span><strong>{total}</strong></div>}
      {step > 0 && <button type="button" disabled={busy} onClick={() => move(step - 1)}>{t('Back')}</button>}
      {step < labels.length - 1 && <button type="button" className="entry-next" disabled={busy || canContinue[step] === false} onClick={() => move(step + 1)}>{t('Continue to')} {labels[step + 1]}</button>}
    </div>
  </form>;
}
