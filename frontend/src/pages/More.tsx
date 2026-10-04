import { IonIcon } from '@ionic/react';
import { barChartOutline, cashOutline, cubeOutline, peopleOutline, receiptOutline, settingsOutline, storefrontOutline, swapHorizontalOutline, walletOutline } from 'ionicons/icons';
import { Link, useSearchParams } from 'react-router-dom';
import { useWorkspace } from '../lib/context';
import { Heading } from '../components/ui';

type Tool = [label: string, href: string, icon: string];

export default function More() {
  const { path, business, t } = useWorkspace();
  const [params, setParams] = useSearchParams();
  const cashier = business.role === 'cashier';
  const groups: { id: string; label: string; tools: Tool[] }[] = [
    { id: 'sales', label: 'Sales tools', tools: [['POS','/pos',storefrontOutline], ['Basket offers','/basket-offers',receiptOutline], ['Price lists','/price-lists',receiptOutline], ['Quotes / orders / jobs','/workflows',receiptOutline]] },
    ...(!cashier ? [
      { id: 'money', label: 'Money tools', tools: [['Collections / payments','/collections',peopleOutline], ['Follow-ups','/followups',peopleOutline], ['Purchases','/purchases',storefrontOutline], ['Expenses','/expenses',walletOutline], ['Regular payments','/regular',peopleOutline], ['Money','/money',cashOutline]] as Tool[] },
      { id: 'stock', label: 'Stock tools', tools: [['Reorder stock','/reorders',storefrontOutline], ['Import CSV','/imports',cubeOutline], ...(['owner','manager'].includes(business.role) ? [['Count stock','/stock/count',cubeOutline] as Tool] : [])] as Tool[] },
      { id: 'business', label: 'Business tools', tools: [['Reports','/reports',barChartOutline], ['Settings','/settings',settingsOutline]] as Tool[] },
    ] : []),
    { id: 'account', label: 'Account', tools: [['My account','/account',peopleOutline], ['Switch business','/businesses',swapHorizontalOutline]] },
  ];
  const selected = groups.find(group => group.id === params.get('section')) || groups[0];
  return <div className="more-tools">
    <Heading title={t('More')}><Link className="more-manual" to={path+'/help'}>{t('User manual')}</Link></Heading>
    <nav className="more-sections" aria-label={t('Tool sections')}>{groups.map(group => <button key={group.id} type="button" aria-pressed={selected.id === group.id} onClick={() => setParams({section:group.id}, {replace:true})}>{t(group.label)}</button>)}</nav>
    <h2 className="more-section-title">{t(selected.label)}</h2>
    <div className="more-grid" role="region" aria-label={t(selected.label)}>{selected.tools.map(([label,suffix,icon]) => <Link className="panel more-card" key={label} to={suffix==='/account'||suffix==='/businesses' ? suffix : path+suffix}><IonIcon icon={icon}/><span>{t(label)}</span></Link>)}</div>
  </div>;
}
