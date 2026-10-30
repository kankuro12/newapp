import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { PanelHelp } from '../components/PanelHelp';
import { manualSection } from './manual';
import { panelGuideKey, panelGuides } from './panelHelp';

vi.mock('@ionic/react', () => ({ IonIcon: () => null }));

// Vitest runs from the frontend folder, where the manual is served from public/.
const manual = readFileSync(resolve(process.cwd(), 'public/help/user-manual.html'), 'utf8');
const chapterExists = (id: string) => manual.includes(`id="${id}"`);

describe('in-panel guides', () => {
  it.each(Object.entries(panelGuides))(
    '%s has steps and a chapter in the manual',
    (_key, guide) => {
      expect(guide.title).not.toBe('');
      expect(guide.steps.length).toBeGreaterThan(0);
      expect(chapterExists(guide.section)).toBe(true);
    },
  );

  it('header Help sections all exist in the manual', () => {
    const suffixes = [
      '',
      '/gym',
      '/pos/full',
      '/pos/setup',
      '/pos',
      '/workflows',
      '/workflow/4',
      '/fulfilment/4',
      '/document/12',
      '/document/12/return',
      '/documents/sale/new',
      '/sales',
      '/purchases',
      '/collections',
      '/followups',
      '/money',
      '/regular',
      '/contacts',
      '/items',
      '/item-categories',
      '/imports',
      '/barcodes',
      '/labels',
      '/price-lists',
      '/basket-offers',
      '/reorders',
      '/stock/count',
      '/reports',
      '/audit',
      '/settings',
      '/opening',
      '/more',
    ];
    for (const suffix of suffixes) {
      expect(chapterExists(manualSection(suffix)), suffix).toBe(true);
    }
  });

  it('maps workspace sub-paths to guides', () => {
    expect(panelGuideKey('')).toBe('home');
    expect(panelGuideKey('/sales')).toBe('sales');
    expect(panelGuideKey('/documents/purchase/new')).toBe('bill-entry');
    expect(panelGuideKey('/document/12')).toBe('document');
    expect(panelGuideKey('/document/12/return')).toBe('returns');
    expect(panelGuideKey('/money')).toBe('money');
    expect(panelGuideKey('/money/receipt/new')).toBe('money-entry');
    expect(panelGuideKey('/contacts/5/trading')).toBe('party-terms');
    expect(panelGuideKey('/contacts/5')).toBe('parties');
    expect(panelGuideKey('/followup/3')).toBe('followups');
    expect(panelGuideKey('/workflows')).toBe('workflows');
    expect(panelGuideKey('/workflows', true)).toBe('job-cards');
    expect(panelGuideKey('/workflows/new/sale')).toBe('workflow-new');
    expect(panelGuideKey('/workflow/9')).toBe('workflow-detail');
    expect(panelGuideKey('/fulfilment/4')).toBe('fulfilment');
    expect(panelGuideKey('/pos/setup')).toBe('pos-setup');
    expect(panelGuideKey('/stock/count')).toBe('stock-count');
    expect(panelGuideKey('/help')).toBeUndefined();
    expect(panelGuideKey('/unknown')).toBeUndefined();
  });
});

describe('PanelHelp', () => {
  it('renders the steps and a link into the workspace manual', () => {
    render(
      <MemoryRouter>
        <PanelHelp guide="sales" path="/app/shop" />
      </MemoryRouter>,
    );
    expect(screen.getByText('How to use this screen')).toBeInTheDocument();
    expect(screen.getByText(panelGuides.sales.steps[0])).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open full chapter' })).toHaveAttribute(
      'href',
      '/app/shop/help?section=bills',
    );
  });

  it('opens the full chapter in a new tab outside the workspace', () => {
    render(<PanelHelp guide="businesses" />);
    const link = screen.getByRole('link', { name: 'Open full chapter' });
    expect(link).toHaveAttribute('href', '/help/user-manual.html#businesses');
    expect(link).toHaveAttribute('target', '_blank');
  });

  it('renders nothing for an unknown guide', () => {
    const { container } = render(<PanelHelp guide="missing" />);
    expect(container).toBeEmptyDOMElement();
  });
});
