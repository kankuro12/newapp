/**
 * In-panel guides: one short "How to use this screen" card per screen.
 * Each guide points at a chapter in public/help/user-manual.html via `section`;
 * panelHelp.test.ts fails if a guide names a chapter the manual does not contain.
 */
export type PanelGuide = {
  title: string;
  summary: string;
  steps: string[];
  tips?: string[];
  section: string;
};

export const panelGuides: Record<string, PanelGuide> = {
  home: {
    title: 'Home',
    summary: 'Start here each day: today’s totals, quick actions and anything needing attention.',
    steps: [
      'Daily actions: tap New sale, New purchase, Receive money or Pay party to start an entry. Record expense opens an expense.',
      'Balances (owner, manager, accountant): Today’s sales, Cash & bank, Customers owe and Still to pay.',
      'Recent activity lists the latest bills; See all opens the full sales list.',
      'Low stock lists items at or below their alert quantity. Previous and Next move between pages.',
    ],
    tips: [
      'Negative stock means items were sold before they were bought or counted. Record the purchase or count stock to correct it.',
      'Starting balances appears here until the opening balances are finalized.',
    ],
    section: 'home',
  },
  gym: {
    title: 'Gym desk',
    summary: 'Members, plans, daily check-in and classes for your gym.',
    steps: [
      'Plans: create a plan with a service item, Fee (NPR), Days, an optional Visit limit and whether it Includes classes.',
      'Members: Add member with a name and phone. The member code is created if you leave it empty.',
      'Join plan or Renew membership: choose the plan, enter Paid now and the account, then save. This posts a normal sale.',
      'Today: check in a member with a membership valid today, or Book member into a class.',
      'End membership: cancel or return the membership bill first, then end the membership.',
    ],
    tips: ['Price changes apply to new joins and renewals only.'],
    section: 'gym',
  },
  'pos-setup': {
    title: 'POS setup',
    summary:
      'Choose this branch’s counter screen and set up its tables, staff, chairs and measurements.',
    steps: [
      'Business type / POS screen: pick the screen that matches your trade.',
      'Tables / staff / chairs: add restaurant tables, takeaway, staff and chairs for appointments.',
      'Item measurement setup: set the entry methods and billed base unit for measured items.',
      'Measurements and service duration: set Duration (minutes) for appointment services.',
      'Product codes / scale setup: open alternate codes and scale label formats.',
      'Save screen keeps changes. Add branch creates a child branch (main-business owner only).',
    ],
    tips: ['The screen type changes how the counter looks. Your branch keeps the same books.'],
    section: 'pos-setup',
  },
  pos: {
    title: 'Quick POS',
    summary: 'The counter for phones and small screens. Its layout follows your business type.',
    steps: [
      'Search / barcode: find an item by name, exact SKU or barcode, then Add to cart.',
      'Cart: check quantities, measurements and the total before offer and tax.',
      'Customer (optional): choose a named customer for agreed prices or a balance due. Link customer for pay later is needed for credit.',
      'Review & pay: tap Check total again if the total changed, then enter Paid now and choose the Payment account.',
      'Save bill + payment completes the sale. If the reply is lost, use Retry original action; never start a second bill.',
    ],
    tips: [
      'Restaurant: Table / takeaway, Open order, Send to kitchen, Mark served, then checkout. Serve all tickets before checkout.',
      'Barber and salon: Book appointment, then Mark arrived and Start service before checkout.',
      'The screen refreshes every 2 seconds while open. Unpaid sales need a named customer.',
    ],
    section: 'pos',
  },
  workflows: {
    title: 'Quotes, orders and jobs',
    summary:
      'Track quotes, sales orders and purchase orders from first quote to delivery and bill.',
    steps: [
      'New quote, New sales order or New purchase order opens the entry form.',
      'Save a quote, mark it sent, then record acceptance or rejection.',
      'Create sales order from an accepted quote.',
      'Open an order and use Deliver goods / complete work or Receive goods / work for quantities completed now.',
      'Bill completed quantities bills what was delivered. Review + create bill is the full-bill route before delivery starts.',
    ],
    tips: [
      'Status changes do not move stock or collect money. Stock moves at delivery or receipt; money moves at billing.',
    ],
    section: 'workflows',
  },
  'job-cards': {
    title: 'Job cards',
    summary: 'Take in, price, track and deliver jobs for your trade.',
    steps: [
      'New job: choose the customer and fill the job details for your trade (vehicle, device, garment, print or fabrication).',
      'Price the job: labour as service items, parts as stock items. For approval first, send a quote and mark it accepted.',
      'Track progress: move the job through In progress → Ready. Overdue work only shows late jobs.',
      'Deliver and bill on handover. Parts leave stock; collect payment now or later.',
      'Search a vehicle number or IMEI, then open a job and choose Service history for earlier jobs.',
    ],
    section: 'job-cards',
  },
  'workflow-new': {
    title: 'New quote or order',
    summary: 'Create a quote, sales order or purchase order.',
    steps: [
      'Choose the party, Business date (BS) and the items with agreed prices.',
      'Add Reference, Specifications / measurements, Valid until (BS) and Fulfilment date (BS) when they apply.',
      'Optionally choose a basket offer and check the current total.',
      'Save. Saving a quote or order does not move stock or money.',
    ],
    tips: ['Quote validity still applies. Copy an expired quote to revise it before accepting.'],
    section: 'workflows',
  },
  'workflow-detail': {
    title: 'Order detail',
    summary: 'See progress, deliveries, bills and slips for one quote, order or job.',
    steps: [
      'Progress shows ordered, completed, remaining, billed and ready-to-bill quantities.',
      'Deliver goods / complete work or Receive goods / work records only the quantities completed now.',
      'Bill completed quantities lists the delivery or receipt records to bill.',
      'Open quantity slip prints the actual action quantities, separately from billing.',
      'Cancel a mistaken action with a BS date and reason (owner, manager or accountant).',
    ],
    tips: [
      'Cancelling a staged bill releases its billing capacity. The recorded delivery or receipt remains.',
    ],
    section: 'workflows',
  },
  fulfilment: {
    title: 'Delivery and billing',
    summary: 'Record each partial delivery or receipt, then bill it when ready.',
    steps: [
      'Enter only the quantities completed now, in the displayed order units. Use available quantity fills the remaining amount.',
      'Choose the Business date (BS). On a first receipt, check Tax is recoverable if it applies.',
      'Choose Review quantities and confirm. Stock moves once now; dues are created when the bill posts.',
      'Bill completed quantities: select the listed records, enter Paid now (zero for later) and choose the Payment account when paying.',
      'Review the total, tax and payment, then confirm. Billing does not move stock again.',
    ],
    section: 'fulfilment',
  },
  sales: {
    title: 'Sales',
    summary: 'All customer bills with status, payment and due amounts.',
    steps: [
      'Filter by status (All entries, Draft, Posted or Cancelled) and use Search.',
      'Open a row to see items, totals, payments, returns and attachments.',
      'New sale starts a bill. Save draft keeps it editable without money or stock changes.',
      'Receive money pays a bill that still has a due amount.',
    ],
    tips: ['Drafts do not change money, stock or dues until they are posted.'],
    section: 'bills',
  },
  purchases: {
    title: 'Purchases',
    summary: 'Supplier bills for goods received, with payment and due amounts.',
    steps: [
      'New purchase records goods bought from a supplier. Enter the Supplier bill reference and Supplier bill date (BS) from the paperwork.',
      'Attach the supplier’s bill or receipt to the saved record.',
      'Pay party clears a supplier due. Record only the amount actually paid.',
      'Return goods on a posted purchase returns goods at their original cost.',
    ],
    section: 'bills',
  },
  expenses: {
    title: 'Expenses',
    summary: 'Business costs that are not stock purchases.',
    steps: [
      'New expense (or Record expense on Home): choose the Category and the amount.',
      'Paid now and Payment account decide whether it is paid now or left as a due.',
      'Attach receipts to the saved expense.',
    ],
    tips: [
      'Monthly rent and salary belong in Regular payments, so they are not typed in again each month.',
    ],
    section: 'bills',
  },
  'bill-entry': {
    title: 'New bill',
    summary: 'Enter a sale, purchase or expense in three steps: Party → Items → Review.',
    steps: [
      'Party: choose the customer or supplier, or Walk-in customer for a cash sale.',
      'Items: add each item, then set quantity, price, discount and tax.',
      'Business date (BS): enter the date in BS, not AD.',
      'Payment status and Paid now: choose full payment, part payment or pay later, and select the Payment account.',
      'Discount & notes: optional bill discount, Due date (BS), references and notes.',
      'Review & save: check the totals. Save draft keeps the bill editable instead.',
    ],
    tips: [
      'A blank Due date (BS) uses the party’s payment days.',
      'If the reply is lost, use Retry original action rather than creating a second bill.',
    ],
    section: 'bill-entry',
  },
  document: {
    title: 'Bill detail',
    summary: 'Read one posted or draft bill and act on it.',
    steps: [
      'Read the lines, Subtotal, Discount, Tax, Total, Paid, Due and any Refund pending.',
      'Receive money (sale) or Pay party (purchase) settles the due amount.',
      'Print prints the bill. Attachments adds a receipt (JPG, PNG or PDF, up to 5 MB).',
      'Copy as new draft makes an editable copy with a new date and fresh terms.',
      'Return goods starts a return. Cancel mistaken entry reverses the whole bill with a reason.',
      'Draft bills: Finish this draft and Post bill, Edit draft, or Delete draft.',
    ],
    tips: ['Posted bills are never edited. Correct them with a return or a cancellation.'],
    section: 'document',
  },
  returns: {
    title: 'Return goods',
    summary: 'Return goods from a posted sale or purchase at its original price and tax.',
    steps: [
      'Choose the lines and the return quantity. You cannot return more than remains unreturned.',
      'Enter the business date (BS) and the reason.',
      'Check Bill reduction and Original prices and tax on the review step.',
      'For cash handed back, choose Refund customer now or Refund received from supplier now and the account. Unpaid parts only reduce the bill.',
      'Confirm. Use Retry original action if the reply is lost.',
    ],
    tips: [
      'Previous items and Next items show two lines at a time. Entered quantities stay as you page.',
    ],
    section: 'corrections',
  },
  collections: {
    title: 'Collections and payments',
    summary: 'Who owes you, and whom you owe, with aging and overdue dues.',
    steps: [
      'Choose Customers owe us or We owe parties.',
      'Search by party name or phone. Open a row to see its open bills.',
      'Receive money or Pay party records the payment against the chosen bill.',
      'View statement opens the party’s full account history.',
      'Use Aging and Due today or overdue to decide what to collect first.',
    ],
    tips: ['Allocate each payment to the correct bill, then check that bill’s remaining due.'],
    section: 'collections',
  },
  followups: {
    title: 'Follow-ups',
    summary: 'Plan who to contact, when, and what happened.',
    steps: [
      'Add follow-up: enter the Follow-up title, Follow-up day (BS), Contact method and Assigned to.',
      'Choose Money direction when the follow-up is about a balance.',
      'After each contact, record What happened / next action and the Next follow-up day (BS), then Save follow-up.',
      'Filter with Assigned to me, or see Due today or overdue.',
    ],
    tips: ['A follow-up never settles a balance and sends no message. Record payments separately.'],
    section: 'followups',
  },
  'party-terms': {
    title: 'Payment terms and prices',
    summary: 'Credit limit, payment days and agreed item prices for one party.',
    steps: [
      'Customer credit limit (NPR): the most unpaid sales allowed for this customer.',
      'Customer payment days and Supplier payment days set default due dates.',
      'Agreed item prices: enter Agreed price (NPR) for an item and Save price. Later bills use it.',
      'Save payment terms saves the credit limit and days.',
    ],
    tips: ['Cashiers see sale-price suggestions only, not credit limits or purchase prices.'],
    section: 'party-terms',
  },
  parties: {
    title: 'Parties',
    summary:
      'Customers, suppliers, employees and rent payees. Keep one record per person or business.',
    steps: [
      'Add party: enter a Name, and an optional Phone.',
      'Party roles: tick Customer, Supplier, Employee and/or Rent. One party can hold several roles.',
      'Contact details (optional): Address, Email and PAN.',
      'Save. When editing, Balances / actions shows payment terms, agreed prices, follow-ups and View statement.',
    ],
    tips: ['Add a role to the existing party instead of creating a duplicate record.'],
    section: 'parties',
  },
  items: {
    title: 'Items',
    summary: 'Products and services with prices, units, stock details and POS settings.',
    steps: [
      'Enter the Name, Item type (stock item or service), Item category and an optional SKU.',
      'Set the Selling price and Unit.',
      'Product options: Billed base unit, enabled entry methods, custom units and Service duration (minutes).',
      'Stock details (optional) and Reorder settings: Low stock alert quantity, Target stock quantity and Preferred supplier.',
    ],
    tips: [
      'Stock changes only through purchases, returns and stock counts, never by editing the item.',
      'Base-unit changes are blocked after stock movements. Plan the unit before receiving stock.',
    ],
    section: 'items',
  },
  categories: {
    title: 'Item categories',
    summary: 'Group items for quick selection in POS and for filtering.',
    steps: [
      'Type a Category name and choose Save category.',
      'Use Filter item category or Search category to find groups.',
      'Assign a category to each item from the Items screen.',
    ],
    section: 'items',
  },
  regular: {
    title: 'Regular payments',
    summary: 'Monthly salary, rent and other regular bills. Each month creates one payable.',
    steps: [
      'Choose Employees, Rent or Other, then Add employee, Add rent, or add a regular bill.',
      'Payee: choose an existing party or create one.',
      'Amount (NPR) and Schedule: enter the First expense date (BS) and the following-month day, or Month end.',
      'Additional setup: Payment label, Category, Enabled, and Automatically record monthly expense + payable.',
      'Monthly history: Pay remaining now, or Pay later. Part paid shows partial payments.',
    ],
    tips: [
      'Pausing stops new months. Existing dues stay. Resuming records the missed months.',
      'Automatic recording needs the server schedule. If a month does not appear, check the deployment.',
    ],
    section: 'regular',
  },
  reorders: {
    title: 'Reorder stock',
    summary: 'Turn low stock into reviewed purchase orders.',
    steps: [
      'Set Target stock on each item first. Items without it need setup.',
      'Select the low-stock items. The suggested quantity uses target stock. Open orders already count toward it.',
      'Choose the Supplier, then check Order quantity and Purchase price (NPR).',
      'Enter the Business date (BS) and optional Fulfilment date (BS).',
      'Choose Review & save to create the purchase order.',
    ],
    tips: [
      'A purchase order does not move stock until goods are received through Receive goods / work.',
    ],
    section: 'reorders',
  },
  imports: {
    title: 'Import CSV',
    summary: 'Bring in parties, items, categories and price lists from a reviewed CSV file.',
    steps: [
      'Choose the Import type and select Download template.',
      'Choose a CSV file or paste CSV text. Use UTF-8, up to 1 MiB.',
      'Preview column mapping: match each column with Choose field, or Ignore column.',
      'Check Columns & errors and See changed fields. Fix errors and preview again.',
      'Choose Review & apply, then Apply reviewed import.',
    ],
    tips: [
      'An import never records purchases, opening stock or party assignments. Import history lists earlier runs.',
      'Export existing records gives you a copy to edit and bring back.',
    ],
    section: 'imports',
  },
  barcodes: {
    title: 'Product codes and scale setup',
    summary: 'Alternate codes for items, and formats for weighed scale labels.',
    steps: [
      'Choose the item, enter alternate codes one per line (Alternate product codes), then Save product codes.',
      'Suggest item code proposes a code for an item that has none.',
      'Scale label formats: Add scale format with Prefix, Product digits, Value digits, Decimal places, Encoded quantity unit and Barcode length (EAN-13 or UPC-A).',
      'Check the format with an Example encoded value, then Save scale formats.',
    ],
    tips: [
      'Changing codes changes no stock or money. Direct scale connections are not supported; scanner entry works with configured codes.',
    ],
    section: 'barcodes',
  },
  labels: {
    title: 'Product labels',
    summary: 'Print Code128 barcode labels on sheets or roll paper.',
    steps: [
      'Choose items, or use Add by category / supplier to add matching items.',
      'Set the Copies for each item.',
      'Paper & layout: choose Quick paper setup (A4 · 60 × 35 mm, or Roll) or Custom / current settings.',
      'Preview labels, then Print labels.',
    ],
    tips: [
      'Remember paper settings stores the layout on this browser only.',
      'Items with no barcode are listed as No barcode and are skipped until they have one.',
    ],
    section: 'labels',
  },
  'basket-offers': {
    title: 'Basket offers',
    summary: 'Named discounts applied at the counter. Owner, manager and accountant set them up.',
    steps: [
      'Offer name and Offer type: Whole basket saving, Item bundle, Buy and get, or Selected items / categories.',
      'Choose the Discount type (Amount (NPR) or Percent (%)), the value, and an optional Maximum saving (NPR).',
      'Set the Minimum spend (NPR) that a bill must reach.',
      'Validity and staff: Starts / Ends (BS), Starts at / Ends at (Nepal time), days, Maximum applications, and Cashier may apply this offer.',
      'Save basket offer. Refresh offer availability shows whether it is active right now.',
    ],
    tips: [
      'One offer applies per bill, before tax. It does not stack with a separate bill discount.',
      'Saved bills keep the offer terms they were posted with.',
    ],
    section: 'basket-offers',
  },
  'price-lists': {
    title: 'Price lists',
    summary: 'Shared price lists for customers and suppliers, with quantity pricing.',
    steps: [
      'New price list: enter the List name and choose a Customer price list or Supplier price list.',
      'Standard price adjustment: Increase or Decrease standard prices by a Percentage.',
      'Quantity pricing method: Volume (one rate for all units) or Slab (each range priced separately).',
      'Item prices / tiers: Add quantity tier with a Minimum quantity and Unit price (NPR).',
      'Set Valid BS dates (optional), then Save price list. Use this list assigns it to parties.',
    ],
    tips: [
      'Agreed prices for a party take priority over price lists. Saved bills keep the prices they were posted with.',
    ],
    section: 'price-lists',
  },
  money: {
    title: 'Money',
    summary:
      'Choose what happened: receive or pay, move money between accounts, owner money, refunds or history.',
    steps: [
      'Receive / pay: Receive money from a customer, or Pay party for a supplier or payee.',
      'Transfers / owner money: Move money between your accounts, Add my money, or Personal withdrawal.',
      'Refunds: Customer refund or Payee refund, when cash actually goes back.',
      'History lists earlier payments. Open one to correct it, if your role allows.',
    ],
    tips: [
      'Transfers and owner money are not sales or expenses. Record only what actually happened.',
    ],
    section: 'payments',
  },
  'money-entry': {
    title: 'Money entry',
    summary:
      'Record one payment, transfer or refund in three steps: Party or Accounts → Amount → Review.',
    steps: [
      'Party (or Accounts for transfers and owner money): choose the party or the accounts.',
      'Amount (NPR): enter the rupee amount and check the Business date (BS).',
      'Bills suggested: pick the bill the money settles. Unapplied amount shows any remainder.',
      'Received into or Paid from: choose the account the money moves through.',
      'Review, then save. An unapplied amount stays as an advance only if you confirm that.',
    ],
    tips: [
      'Allow confirmed bank overdraft is for the owner or accountant only. It is recorded in the audit history.',
      'If the reply is lost, use Retry original action. Do not repeat the payment.',
    ],
    section: 'money-entry',
  },
  'stock-count': {
    title: 'Count stock',
    summary: 'Record the physical quantity of an item and correct recorded stock.',
    steps: [
      'Item: choose the item to count.',
      'Count: enter the Physically counted quantity. Recorded stock shows the system figure.',
      'Extra units need a Cost per extra unit (NPR). Confirm Zero-cost extra stock only when that is correct.',
      'Review shows the difference. Confirm to save. Continue does not save.',
      'Count history lists earlier counts.',
    ],
    tips: ['A count corrects stock. It is not a sale and does not collect money.'],
    section: 'stock-count',
  },
  reports: {
    title: 'Reports',
    summary: 'Financial and stock reports for a range of BS dates.',
    steps: [
      'Choose a report, then set the Filters: From and Date (BS). Party statements also need the party and channel.',
      'Show results opens the figures. Filters returns you to change dates.',
      'Overview gives Net sales, Cost of goods sold, Net profit, and the balance figures.',
      'Previous rows and Next rows reveal long reports. Print includes every row.',
      'Download CSV exports the chosen report with its filters.',
    ],
    tips: ['Reports only read the books. Reading a report never changes a figure.'],
    section: 'reports',
  },
  opening: {
    title: 'Starting balances',
    summary: 'One-time setup: enter where the business stands on the first day of these books.',
    steps: [
      'Cash & bank: set the Starting date (BS), then enter each account’s balance (NPR).',
      'Items in stock: enter the quantity and the total starting value at your cost, not the selling price.',
      'Party dues: enter what customers owe you and what you owe suppliers (Who owes whom?).',
      'Review your starting point, then Confirm starting balances.',
    ],
    tips: [
      'A section with no amounts is valid only if that amount is truly zero.',
      'After confirmation the balances are fixed. Later changes go through sales, purchases, payments and counts.',
    ],
    section: 'opening',
  },
  settings: {
    title: 'Settings',
    summary:
      'Business details, staff, cash and bank accounts, expense categories, period lock and owner money.',
    steps: [
      'Business: name, address, phone, PAN, business language and Default tax rate (%).',
      'Staff: Invite staff by Email and Role. Change a member’s Staff role with Confirm your password.',
      'Cash & bank: Add cash / bank account, with an Account name and a Kind.',
      'Expense categories: add the categories used by expenses and regular payments.',
      'Lock period: set Close through (BS) with a Reason and your password. Locked dates cannot change.',
      'Owner money history shows owner contributions and withdrawals.',
    ],
    tips: [
      'Business, Staff, Cash & bank and Expense categories are for owners. Period lock needs the owner or accountant.',
    ],
    section: 'settings',
  },
  audit: {
    title: 'Audit history',
    summary: 'Append-only record of business actions, in date order.',
    steps: [
      'Owners and accountants open this from Settings.',
      'Browse pages with the page controls. Entries cannot be edited or removed.',
    ],
    tips: ['Use it to see who did what and when before correcting a record.'],
    section: 'audit',
  },
  more: {
    title: 'More',
    summary: 'Every tool, grouped by what you are doing.',
    steps: [
      'Sales tools: POS, Basket offers, Price lists and Quotes / orders / jobs. Gym desk appears for gyms.',
      'Money tools: Collections / payments, Follow-ups, Purchases, Expenses, Regular payments and Money.',
      'Stock tools: Reorder stock, Import CSV and Count stock.',
      'Business tools and Account: Reports, Settings, Notifications, AI agents, Packages and Switch business.',
      'User manual opens this guide.',
    ],
    tips: [
      'Your role decides which tools you see. Cashiers see the sales tools and the user manual.',
    ],
    section: 'more',
  },
  businesses: {
    title: 'Your businesses',
    summary: 'Choose a business book to work in, or start a new one.',
    steps: [
      'Tap a business to open its books. The business name and type show at the top of each screen.',
      'Previous businesses and Next businesses reveal the rest of your list.',
      'New business opens setup: Business name, Address, Business type and Billing account, then Create business.',
      'To join a business, open the invitation link while signed in with the invited email.',
    ],
    tips: [
      'Each business has its own books, stock and staff. Access to one does not give access to another.',
    ],
    section: 'businesses',
  },
  billing: {
    title: 'Accounts and packages',
    summary: 'See your package, when it ends and which businesses it covers. Buy or renew it here.',
    steps: [
      'Available packages lists each plan with its duration, products and business limit.',
      'Buy or renew package: choose the package, Payment currency and Payment provider.',
      'Create checkout, then Continue with your provider.',
      'Return here and choose Check payment status. Access changes only after the provider confirms payment.',
      'If you started a payment and did not pay, choose I did not pay — start over.',
    ],
    tips: [
      'Package payments are recorded here. They do not create sales, expenses or cash entries in your business books.',
    ],
    section: 'packages',
  },
  agents: {
    title: 'AI agents',
    summary: 'Give an AI assistant (MCP) limited access to one business.',
    steps: [
      'Create agent access: enter a Name, choose the Business and how long it lasts (Expires after).',
      'Tick Allow recording sales only if the assistant should save sales. Without it, the assistant can only read.',
      'Choose Create token and copy it straight away. It is shown once.',
      'Use the Claude Code setup line, or the JSON block for other MCP clients.',
      'Revoke a token in Your agent tokens when you no longer need it.',
    ],
    tips: [
      'Every agent sale is marked “Recorded by AI agent”. Agents cannot change settings, prices, staff or past bills.',
    ],
    section: 'agents',
  },
  notifications: {
    title: 'Notifications',
    summary: 'Choose which alerts reach you, and manage this browser and your devices.',
    steps: [
      'Delivery preferences: package reminders and promotions. Promotions stay off until you choose them.',
      'Push on this browser: choose Enable push on this device, then allow notifications. Disable push on this device removes it.',
      'Your devices: Revoke device removes a device you no longer use.',
    ],
    tips: ['Security emails and payment receipts always arrive. Promotions need your choice.'],
    section: 'account-messages',
  },
  auth: {
    title: 'Sign in and your account',
    summary: 'Create your account, verify your email, and sign in to your business.',
    steps: [
      'Register with Your name, Country, Phone number (without the country code), Email and Password.',
      'Open the verification link in your email before you open a business.',
      'Business sign in is for staff. Platform administrators use their own separate sign-in.',
      'My account: Update details, or Change password with Current password, New password and Confirm new password.',
    ],
    tips: ['Never share password-reset links or verification codes.'],
    section: 'start',
  },
  platform: {
    title: 'Business access',
    summary: 'Platform administrators adjust access dates for one business.',
    steps: [
      'Search businesses and select the one to change.',
      'Set the Access status and Access until (local time).',
      'Enter a Reason and your Confirm admin password, then Update access.',
    ],
    tips: ['Every change is written to the audit log. Financial books are never shown here.'],
    section: 'platform',
  },
  'platform-billing': {
    title: 'Accounts, payments and messages',
    summary: 'Platform tools for accounts, payments, campaigns and message delivery.',
    steps: [
      'Accounts: search with Search accounts and suspend or restore an account.',
      'Payments: filter by Payment status. Record a refund or dispute for a confirmed payment.',
      'Campaigns: New campaign, then a Name, Message and Audience (all owners, trial, paid or expired). Save draft, then send now or use Send later.',
      'Deliveries: outcomes by event and channel, with Delivery status and Outcome. Message text is not shown.',
    ],
    tips: ['Each action needs your admin password and a reason.'],
    section: 'platform-billing',
  },
  'platform-packages': {
    title: 'Manage packages',
    summary: 'Package names, durations, trial days, business limits and prices.',
    steps: [
      'Enter the Package name, Duration days, Trial days and Business limit.',
      'Set the enabled products and exact prices.',
      'Enter a Change reason and your Confirm admin password, then save.',
    ],
    tips: ['Changes apply to future subscriptions only.'],
    section: 'packages',
  },
};

/** Ordered: the first route that matches the workspace sub-path wins. */
const routes: [route: string, guide: string][] = [
  ['/gym', 'gym'],
  ['/pos/setup', 'pos-setup'],
  ['/pos', 'pos'],
  ['/workflows/new', 'workflow-new'],
  ['/workflow', 'workflow-detail'],
  ['/workflows', 'workflows'],
  ['/fulfilment', 'fulfilment'],
  ['/sales', 'sales'],
  ['/purchases', 'purchases'],
  ['/expenses', 'expenses'],
  ['/documents', 'bill-entry'],
  ['/document', 'document'],
  ['/collections', 'collections'],
  ['/followups', 'followups'],
  ['/followup', 'followups'],
  ['/contacts', 'parties'],
  ['/regular', 'regular'],
  ['/item-categories', 'categories'],
  ['/items', 'items'],
  ['/reorders', 'reorders'],
  ['/imports', 'imports'],
  ['/barcodes', 'barcodes'],
  ['/labels', 'labels'],
  ['/basket-offers', 'basket-offers'],
  ['/price-lists', 'price-lists'],
  ['/money', 'money'],
  ['/stock/count', 'stock-count'],
  ['/reports', 'reports'],
  ['/opening', 'opening'],
  ['/settings', 'settings'],
  ['/audit', 'audit'],
  ['/more', 'more'],
];

/**
 * Guide key for a workspace sub-path such as "/sales" (the path after /app/{slug}).
 * Job-card businesses lead with "Job cards" instead of "Quotes, orders and jobs".
 */
export function panelGuideKey(suffix: string, jobBusiness = false): string | undefined {
  if (suffix === '') return 'home';
  if (/^\/document\/[^/]+\/return$/.test(suffix)) return 'returns';
  if (/^\/contacts\/[^/]+\/trading$/.test(suffix)) return 'party-terms';
  if (/^\/money\/[^/]+\/new$/.test(suffix)) return 'money-entry';
  if (suffix === '/workflows') return jobBusiness ? 'job-cards' : 'workflows';
  const hit = routes.find(([route]) => suffix === route || suffix.startsWith(route + '/'));
  return hit?.[1];
}
