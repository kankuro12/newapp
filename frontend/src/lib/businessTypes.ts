/** Business types offered when creating a business; each is also a package product (general = bookkeeping). */
export const businessTypes: Record<string, string> = {
  general: 'General business',
  meat: 'Meat shop',
  restaurant: 'Restaurant',
  barber: 'Barber',
  salon: 'Salon',
  milk: 'Dairy',
  glass: 'Glass',
  wood: 'Wood',
  gym: 'Gym',
  garage: 'Auto garage',
  repair: 'Mobile / device repair',
  laundry: 'Laundry / dry cleaning',
  tailor: 'Tailoring',
  printing: 'Printing / signage',
  fabrication: 'Iron works / grill fabrication',
};

/** Types whose daily tool is the job-card board (quotes / orders / jobs with niche details). */
export const jobTypes = ['garage', 'repair', 'laundry', 'tailor', 'printing', 'fabrication'];

export const packageProducts = [
  'bookkeeping',
  ...Object.keys(businessTypes).filter((type) => type !== 'general'),
];

export const businessTypeLabel = (type?: string | null) =>
  businessTypes[type || 'general'] || type || 'General business';
