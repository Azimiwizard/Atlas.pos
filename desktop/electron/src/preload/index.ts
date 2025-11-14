import { contextBridge, ipcRenderer } from 'electron';

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

type Listener<T> = (payload: T) => void;

function registerEvent<T>(channel: string, callback: Listener<T>) {
  const listener = (_event: Electron.IpcRendererEvent, data: T) => {
    callback(data);
  };
  ipcRenderer.on(channel, listener);
  return () => {
    ipcRenderer.removeListener(channel, listener);
  };
}

const atlasDesktop = {
  platform: process.platform,
  isDesktop: true,
  secureStore: {
    getToken: () => ipcRenderer.invoke('secure-store:get-token') as Promise<string | null>,
    setToken: (token: string | null) =>
      ipcRenderer.invoke('secure-store:set-token', token) as Promise<void>,
  },
  offline: {
    getCart: () => ipcRenderer.invoke('offline:get-cart') as Promise<CartSnapshot>,
    saveCart: (snapshot: CartSnapshot) =>
      ipcRenderer.invoke('offline:save-cart', snapshot) as Promise<void>,
    clearCart: () => ipcRenderer.invoke('offline:clear-cart') as Promise<void>,
    listOrders: () => ipcRenderer.invoke('offline:list-orders') as Promise<OfflineOrder[]>,
    queueOrder: (order: OfflineOrder) =>
      ipcRenderer.invoke('offline:queue-order', order) as Promise<void>,
    removeOrder: (id: string) =>
      ipcRenderer.invoke('offline:remove-order', id) as Promise<void>,
  },
  printer: {
    listDevices: () => ipcRenderer.invoke('printer:list-devices') as Promise<UsbPrinterDevice[]>,
    printReceipt: (payload: PrintReceiptPayload) =>
      ipcRenderer.invoke('printer:print-receipt', payload) as Promise<void>,
  },
  updater: {
    check: () => ipcRenderer.invoke('updater:check') as Promise<void>,
    download: () => ipcRenderer.invoke('updater:download') as Promise<void>,
    quitAndInstall: () => ipcRenderer.invoke('updater:quit-and-install') as Promise<void>,
    onUpdateAvailable: (listener: Listener<unknown>) =>
      registerEvent('updater:update-available', listener),
    onUpdateNotAvailable: (listener: Listener<unknown>) =>
      registerEvent('updater:update-not-available', listener),
    onDownloadProgress: (listener: Listener<unknown>) =>
      registerEvent('updater:download-progress', listener),
    onUpdateDownloaded: (listener: Listener<unknown>) =>
      registerEvent('updater:update-downloaded', listener),
    onError: (listener: Listener<unknown>) => registerEvent('updater:error', listener),
  },
  app: {
    reload: () => ipcRenderer.invoke('app:reload') as Promise<void>,
  },
};

contextBridge.exposeInMainWorld('atlasDesktop', atlasDesktop);
