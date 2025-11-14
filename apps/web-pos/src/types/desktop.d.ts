type CartLine = {
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

type CartSnapshot = {
  lines: CartLine[];
  discount: number;
};

type OfflineOrder = {
  id: string;
  createdAt: string;
  payload: unknown;
  retries?: number;
  lastTriedAt?: string | null;
};

type UsbPrinterDevice = {
  type: 'usb';
  vendorId: number;
  productId: number;
  deviceAddress?: number;
  serialNumber?: string | null;
};

type ReceiptLineItem = {
  name: string;
  qty: number;
  unitPrice: number;
  total: number;
};

type ReceiptTotals = {
  subtotal: number;
  discount: number;
  tax: number;
  total: number;
  refunded?: number;
  net?: number;
};

type ReceiptMetadata = {
  orderId?: string | null;
  orderNumber?: string | null;
  cashier?: string | null;
  storeName?: string | null;
  storeCode?: string | null;
  printedAt?: string;
};

type PrintReceiptPayload = {
  device?: UsbPrinterDevice;
  items: ReceiptLineItem[];
  totals: ReceiptTotals;
  metadata?: ReceiptMetadata;
  footer?: string[];
};

type DesktopUpdaterAPI = {
  check: () => Promise<void>;
  download: () => Promise<void>;
  quitAndInstall: () => Promise<void>;
  onUpdateAvailable: (listener: (payload: unknown) => void) => () => void;
  onUpdateNotAvailable: (listener: (payload: unknown) => void) => () => void;
  onDownloadProgress: (listener: (payload: unknown) => void) => () => void;
  onUpdateDownloaded: (listener: (payload: unknown) => void) => () => void;
  onError: (listener: (payload: unknown) => void) => () => void;
};

type DesktopAPI = {
  platform: NodeJS.Platform;
  isDesktop: true;
  secureStore: {
    getToken: () => Promise<string | null>;
    setToken: (token: string | null) => Promise<void>;
  };
  offline: {
    getCart: () => Promise<CartSnapshot>;
    saveCart: (snapshot: CartSnapshot) => Promise<void>;
    clearCart: () => Promise<void>;
    listOrders: () => Promise<OfflineOrder[]>;
    queueOrder: (order: OfflineOrder) => Promise<void>;
    removeOrder: (id: string) => Promise<void>;
  };
  printer: {
    listDevices: () => Promise<UsbPrinterDevice[]>;
    printReceipt: (payload: PrintReceiptPayload) => Promise<void>;
  };
  updater: DesktopUpdaterAPI;
  app: {
    reload: () => Promise<void>;
  };
};

declare global {
  interface Window {
    atlasDesktop?: DesktopAPI;
  }
}

export {};
