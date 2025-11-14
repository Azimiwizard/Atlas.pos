import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { getDesktopApi } from '../lib/desktop';

export type CartLine = {
  variantId: string;
  productId: string;
  title: string;
  sku?: string | null;
  price: number;
  qty: number;
  categories: Array<{ id: string; name: string }>;
  taxes: Array<{ id: string; name: string; rate: number; inclusive: boolean }>;
  stockOnHand?: number | null;
};

type CartTotals = {
  subtotal: number;
  tax: number;
  discount: number;
  total: number;
};

type CartContextValue = {
  lines: CartLine[];
  totals: CartTotals;
  discount: number;
  setDiscount: (value: number) => void;
  addItem: (line: CartLine) => void;
  increment: (variantId: string) => void;
  decrement: (variantId: string) => void;
  remove: (variantId: string) => void;
  clear: () => void;
};

const CartContext = createContext<CartContextValue | undefined>(undefined);

function useCartState(initial: CartLine[] = []): CartContextValue {
  const desktopApi = getDesktopApi();
  const [lines, setLines] = useState<CartLine[]>(initial);
  const [discount, setDiscount] = useState(0);
  const hasLoadedDesktopSnapshot = useRef(!desktopApi);
  const applyingSnapshot = useRef(false);

  useEffect(() => {
    if (!desktopApi) {
      return;
    }

    let active = true;

    desktopApi.offline
      .getCart()
      .then((snapshot) => {
        if (!active) {
          return;
        }
        applyingSnapshot.current = true;
        setLines(snapshot.lines ?? []);
        setDiscount(snapshot.discount ?? 0);
        hasLoadedDesktopSnapshot.current = true;
        applyingSnapshot.current = false;
      })
      .catch(() => {
        hasLoadedDesktopSnapshot.current = true;
        applyingSnapshot.current = false;
      });

    return () => {
      active = false;
    };
  }, [desktopApi]);

  useEffect(() => {
    if (!desktopApi) {
      return;
    }

    if (!hasLoadedDesktopSnapshot.current || applyingSnapshot.current) {
      return;
    }

    void desktopApi.offline
      .saveCart({ lines, discount })
      .catch(() => undefined);
  }, [desktopApi, lines, discount]);

  const totals = useMemo(() => {
    const subtotal = lines.reduce((sum, line) => sum + line.price * line.qty, 0);
    const tax = 0;
    const total = Math.max(subtotal - discount, 0);

    return {
      subtotal,
      tax,
      discount,
      total,
    };
  }, [lines, discount]);

  const addItem = useCallback((line: CartLine) => {
    setLines((current) => {
      const existing = current.find((item) => item.variantId === line.variantId);
      if (existing) {
        return current.map((item) =>
          item.variantId === line.variantId
            ? {
                ...item,
                qty: item.qty + line.qty,
                stockOnHand: line.stockOnHand ?? item.stockOnHand,
              }
            : item
        );
      }
      return [...current, line];
    });
  }, []);

  const increment = useCallback((variantId: string) => {
    setLines((current) =>
      current.map((item) =>
        item.variantId === variantId ? { ...item, qty: item.qty + 1 } : item
      )
    );
  }, []);

  const decrement = useCallback((variantId: string) => {
    setLines((current) =>
      current
        .map((item) =>
          item.variantId === variantId ? { ...item, qty: item.qty - 1 } : item
        )
        .filter((item) => item.qty > 0)
    );
  }, []);

  const remove = useCallback((variantId: string) => {
    setLines((current) => current.filter((item) => item.variantId !== variantId));
  }, []);

  const clear = useCallback(() => {
    setLines([]);
    setDiscount(0);
    if (desktopApi) {
      void desktopApi.offline.clearCart();
    }
  }, [desktopApi]);

  return {
    lines,
    totals,
    discount,
    setDiscount,
    addItem,
    increment,
    decrement,
    remove,
    clear,
  };
}

export function CartProvider({ children }: { children: ReactNode }) {
  const value = useCartState();

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart(): CartContextValue {
  const context = useContext(CartContext);
  if (!context) {
    throw new Error('useCart must be used within a CartProvider');
  }
  return context;
}
