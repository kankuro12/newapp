import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { Context } from '../lib/context';
import type { Business } from '../lib/types';
import More from './More';

vi.mock('@ionic/react', () => ({ IonIcon: () => null }));

function page(role='owner', initial='/app/shop/more') {
  return <MemoryRouter initialEntries={[initial]}><Context.Provider value={{business:{role} as Business,base:'/api/app/shop',path:'/app/shop',today:20830103,revision:0,changed:vi.fn(),t:s=>s,locale:'en'}}><More/></Context.Provider></MemoryRouter>;
}

it('shows a small section at a time, keeps manual available and retains all tools through sections', () => {
  render(page());
  expect(screen.getByRole('link', {name:'User manual'})).toHaveAttribute('href', '/app/shop/help');
  expect(screen.getByRole('link', {name:'POS'})).toHaveAttribute('href', '/app/shop/pos');
  expect(screen.queryByRole('link', {name:'Purchases'})).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', {name:'Money tools'}));
  expect(screen.getByRole('button', {name:'Money tools'})).toHaveAttribute('aria-pressed','true');
  expect(screen.getByRole('link', {name:'Purchases'})).toHaveAttribute('href','/app/shop/purchases');
  expect(screen.getByRole('link', {name:'Regular payments'})).toBeInTheDocument();
  expect(screen.queryByRole('link', {name:'Basket offers'})).not.toBeInTheDocument();
  expect(screen.getByRole('link', {name:'User manual'})).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', {name:'Stock tools'}));
  expect(screen.getByRole('link', {name:'Reorder stock'})).toBeInTheDocument();
  expect(screen.getByRole('link', {name:'Import CSV'})).toBeInTheDocument();
  expect(screen.getByRole('link', {name:'Count stock'})).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', {name:'Business tools'}));
  expect(screen.getByRole('link', {name:'Reports'})).toBeInTheDocument();
  expect(screen.getByRole('link', {name:'Settings'})).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', {name:'Account'}));
  expect(screen.getByRole('link', {name:'My account'})).toHaveAttribute('href','/account');
  expect(screen.getByRole('link', {name:'Switch business'})).toHaveAttribute('href','/businesses');
});

it('restores linked section and prevents cashier from opening hidden tool groups', () => {
  const view=render(page('accountant','/app/shop/more?section=stock'));
  expect(screen.getByRole('button', {name:'Stock tools'})).toHaveAttribute('aria-pressed','true');
  expect(screen.getByRole('link', {name:'Reorder stock'})).toBeInTheDocument();
  expect(screen.queryByRole('link', {name:'Count stock'})).not.toBeInTheDocument();
  view.unmount();
  render(page('cashier','/app/shop/more?section=money'));
  expect(screen.getByRole('button', {name:'Sales tools'})).toHaveAttribute('aria-pressed','true');
  expect(screen.queryByRole('button', {name:'Money tools'})).not.toBeInTheDocument();
  expect(screen.queryByRole('link', {name:'Purchases'})).not.toBeInTheDocument();
  expect(screen.getByRole('link', {name:'User manual'})).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', {name:'Account'}));
  expect(screen.getByRole('link', {name:'Switch business'})).toBeInTheDocument();
});
