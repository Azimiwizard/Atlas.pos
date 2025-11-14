import { createContext, useContext } from 'react';
import type { Store } from '../features/stores/api';

export type StoreContextValue = {
  stores: Store[];
  currentStore: Store | null;
  currentStoreId: string | null;
  setCurrentStoreId: (storeId: string | null) => void;
  loading: boolean;
};

export const StoreContext = createContext<StoreContextValue | undefined>(undefined);

export function useStore(): StoreContextValue {
  const context = useContext(StoreContext);

  if (!context) {
    throw new Error('useStore must be used within a StoreProvider');
  }

  return context;
}

