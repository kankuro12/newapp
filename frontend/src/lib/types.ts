export interface OfferProof {
  id: string;
  name: string;
  discount_paisa: string;
  line_bases?: { line_discount_paisa: string }[];
}
export interface OfferEntry {
  contact_id?: string;
  business_date_bs?: number | string;
  basket_offer_id?: string | null;
  invoice_discount?: string;
  invoice_discount_bps?: string;
  promotional_confirmed?: boolean;
  lines?: {
    qty: string;
    unit_price: string;
    discount?: string;
    discount_bps?: string;
    tax_category?: string;
    tax_bps?: string;
  }[];
}
export interface User {
  country_code?: string | null;
  phone?: string | null;
  id: string;
  name: string;
  email: string;
  email_verified_at: string | null;
  locale: 'en' | 'ne';
}
export interface PriceListRule {
  item_id: string;
  item_name: string;
  min_qty_milli: string;
  price_paisa: string;
  unit_snapshot: string;
  pos_unit: string;
  item_kind: string;
  current_unit_snapshot?: string;
  current_pos_unit?: string;
  current_item_kind?: string;
  item_archived?: boolean;
}
export interface PriceList {
  pricing_scheme?: 'volume' | 'slab';
  id: string;
  name: string;
  channel: 'sale' | 'purchase';
  enabled: boolean;
  version: number;
  adjustment_bps: string;
  starts_bs: number | null;
  ends_bs: number | null;
  rules?: PriceListRule[];
}
export interface Business {
  billing_account_id?: string;
  pos_profile?: string;
  parent_tenant_id?: string | null;
  id: string;
  slug: string;
  name: string;
  role: string;
  opening_finalized_at: string | null;
  opening_date_bs: number | null;
  closed_through_bs: number | null;
  access_status: string;
  access_until: string | null;
  trial_ends_at: string | null;
  default_locale: 'en' | 'ne';
  address: string | null;
  phone: string | null;
  pan: string | null;
  tax_recording_enabled: boolean;
  default_tax_bps: string;
}
export interface Party {
  sales_price_list_id?: string | null;
  purchase_price_list_id?: string | null;
  sales_terms_days?: number;
  purchase_terms_days?: number;
  credit_limit_paisa?: string | null;
  trading_version?: number;
  id: string;
  name: string;
  phone: string | null;
  is_customer: boolean;
  is_supplier: boolean;
  is_employee: boolean;
  is_rent: boolean;
  is_system: boolean;
  receivable_paisa?: string;
  payable_paisa?: string;
  email?: string;
  address?: string;
  pan?: string;
  archived_at?: string | null;
}
export interface Item {
  aliases?: string[];
  category_id?: string | null;
  category_name?: string | null;
  preferred_supplier_id?: string | null;
  preferred_supplier_name?: string | null;
  reorder_target_qty_milli?: string;
  suggested_price_paisa?: string | null;
  pos_unit?: string;
  pos_methods?: string[] | null;
  pos_custom_units?: { label: string; qty: string }[] | null;
  service_minutes?: number;
  id: string;
  name: string;
  sku: string | null;
  kind: 'stock' | 'service';
  unit_label: string;
  sale_price_paisa: string;
  last_purchase_price_paisa?: string | null;
  qty_milli: string;
  value_paisa?: string;
  low_stock_qty_milli: string;
  default_tax_bps: string;
  default_tax_category: string;
  archived_at?: string | null;
}
export interface Account {
  id: string;
  name: string;
  system_key: string | null;
  balance_paisa?: string;
  money_kind?: string;
}
export interface ItemCategory {
  id: string;
  name: string;
  version: number;
  archived_at?: string | null;
}
export interface Category {
  id: string;
  name: string;
  account_id: string;
}
export interface Lookup {
  item_categories?: ItemCategory[];
  contacts: Party[];
  items: Item[];
  accounts: Account[];
  categories: Category[];
  channels?: { receivables_id: string; payables_id: string };
}
export interface SourceOrderSnapshot {
  id: string;
  number: string;
  version?: number;
  allocation: string;
  source_bill_id?: string;
  offer?: OfferProof | null;
  lines: {
    position: number;
    order_position: number;
    gross_rounding_paisa: string;
    tax_rounding_paisa: string;
    cost_variance_paisa?: string;
  }[];
}
export interface DocLine {
  measurement_snapshot?: Record<string, unknown> | null;
  id: string;
  item_id: string | null;
  expense_category_id: string | null;
  description: string;
  unit_snapshot: string;
  qty_milli: string;
  unit_price_paisa: string;
  line_discount_paisa: string;
  net_base_paisa: string;
  total_paisa: string;
  tax_paisa: string;
  tax_bps: string;
  tax_category: string;
  inventory_cost_paisa?: string;
  returnable_qty_milli: string;
}
export interface Payment {
  id: string;
  kind: string;
  status: string;
  amount_paisa: string;
  business_date_bs: number;
  reference?: string | null;
}
export interface Doc {
  source_order_snapshot?: SourceOrderSnapshot | null;
  workflow_id?: string | null;
  basket_offer_id?: string | null;
  basket_offer_snapshot?: OfferProof | null;
  draft_input?: OfferEntry;
  due_date_bs?: number | null;
  id: string;
  type: string;
  status: string;
  version: number;
  number: string | null;
  business_date_bs: number;
  contact_id: string;
  total_paisa: string;
  subtotal_paisa: string;
  invoice_discount_paisa: string;
  line_discount_paisa: string;
  tax_paisa: string;
  due_paisa: string;
  credit_paisa: string;
  net_settled_paisa: string;
  notes: string | null;
  party_snapshot: Party;
  business_snapshot: Business;
  cancellation_reason?: string;
  cancellation_date_bs?: number;
  lines: DocLine[];
  payments: Payment[];
  returns: Doc[];
  attachments: { id: string; original_name: string }[];
}
export interface Page<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}
export interface FulfilmentLine {
  id: string;
  position: number;
  qty_milli: string;
  billable_qty_milli: string;
  returnable_qty_milli: string;
  item_snapshot: { description: string; unit_snapshot: string };
}
export interface Fulfilment {
  id: string;
  workflow_id: string;
  number: string;
  kind: 'delivery' | 'receipt' | 'delivery_return' | 'receipt_return';
  status: string;
  version: number;
  business_date_bs: number;
  source_id?: string | null;
  vat_recoverable: boolean | number;
  reference?: string | null;
  notes?: string | null;
  cancellation_reason?: string | null;
  lines: FulfilmentLine[];
}
export interface FulfilmentProgress {
  active: boolean;
  lines: {
    position: number;
    description: string;
    unit_snapshot: string;
    ordered_qty_milli: string;
    completed_qty_milli: string;
    remaining_qty_milli: string;
    billed_qty_milli: string;
    billed_returned_qty_milli: string;
    billable_qty_milli: string;
  }[];
  activity: Fulfilment[];
  bills: {
    id: string;
    number: string;
    status: string;
    business_date_bs: number;
    total_paisa: string;
  }[];
}
export interface Workflow {
  fulfilment?: FulfilmentProgress;
  fulfilment_vat_recoverable?: boolean | number | null;
  bill_input?: {
    basket_offer_id?: string | null;
    basket_offer_snapshot?: OfferProof | null;
    offer_input?: OfferEntry;
  };
  id: string;
  kind: 'quote' | 'sales_order' | 'purchase_order';
  number: string;
  status: string;
  version: number;
  niche: string;
  title?: string | null;
  reference?: string | null;
  specifications?: string | null;
  notes?: string | null;
  cancellation_reason?: string | null;
  business_date_bs: number;
  due_date_bs?: number | null;
  valid_until_bs?: number | null;
  contact_id: string;
  party_snapshot: Party;
  business_snapshot: Business;
  lines: DocLine[];
  subtotal_paisa: string;
  line_discount_paisa: string;
  invoice_discount_paisa: string;
  tax_paisa: string;
  total_paisa: string;
  source_id?: string | null;
  child_id?: string | null;
  document_id?: string | null;
  bill_status?: string | null;
  expired: boolean;
  overdue: boolean;
}
export interface Overview {
  bank_overdrafts_paisa: string;
  cash_paisa: string;
  inventory_paisa: string;
  receivables_paisa: string;
  payables_paisa: string;
  sales_paisa: string;
  cogs_paisa: string;
  profit_paisa: string;
  customer_credits_paisa: string;
  supplier_advances_paisa: string;
  assets_paisa: string;
  liabilities_paisa: string;
  equity_paisa: string;
  debit_paisa: string;
  credit_paisa: string;
}
export interface Dashboard extends Overview {
  today_bs: number;
  role: string;
  tenant: Business;
  recent: Doc[];
  low_stock: Item[];
}
