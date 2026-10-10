import { createContext, useContext } from 'react';
import type { Business } from './types';
export interface WorkspaceContext {
  business: Business;
  base: string;
  path: string;
  today: number;
  revision: number;
  changed: () => void;
  t: (text: string) => string;
  locale: 'en' | 'ne';
}
export const Context = createContext<WorkspaceContext | null>(null);
export const useWorkspace = () => {
  const context = useContext(Context);
  if (!context) throw new Error('Business context required');
  return context;
};
