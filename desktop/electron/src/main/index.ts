import { app, BrowserWindow, ipcMain, nativeTheme } from 'electron';
import path from 'node:path';
import log from 'electron-log';
import {
  initializeSecureStore,
  getApiToken,
  setApiToken,
} from './secureStore';
import {
  clearCart,
  initializeOfflineStore,
  listQueuedOrders,
  loadCart,
  queueOrder,
  removeQueuedOrder,
  saveCart,
  type CartSnapshot,
  type OfflineOrder,
} from './offlineStore';
import {
  listUsbPrinters,
  printReceipt,
  type PrintReceiptPayload,
} from './printer';
import {
  checkForUpdates,
  downloadUpdate,
  initializeAutoUpdater,
  quitAndInstall,
} from './updater';

const isDev = process.env.NODE_ENV === 'development' || !app.isPackaged;

let mainWindow: BrowserWindow | null = null;

process.env.ELECTRON_DISABLE_SECURITY_WARNINGS = 'true';

function getPreloadPath(): string {
  return path.join(__dirname, '..', 'preload', 'index.js');
}

function getProdIndexPath(): string {
  return path.join(process.resourcesPath, 'web', 'index.html');
}

async function loadMainWindowContents(window: BrowserWindow): Promise<void> {
  if (isDev) {
    const devServerUrl = process.env.ELECTRON_START_URL ?? 'http://localhost:5173';
    await window.loadURL(devServerUrl);
  } else {
    const indexPath = getProdIndexPath();
    await window.loadFile(indexPath);
  }
}

function createWindow(): BrowserWindow {
  const window = new BrowserWindow({
    width: 1280,
    height: 800,
    show: false,
    fullscreen: true,
    kiosk: true,
    autoHideMenuBar: true,
    backgroundColor: nativeTheme.shouldUseDarkColors ? '#111111' : '#ffffff',
    webPreferences: {
      preload: getPreloadPath(),
      contextIsolation: true,
      sandbox: false,
      nodeIntegration: false,
      partition: 'persist:atlas-pos',
    },
  });

  window.once('ready-to-show', () => {
    window.show();
    if (isDev) {
      window.maximize();
      try {
        window.webContents.openDevTools({ mode: 'detach' });
      } catch (error) {
        log.warn('Failed to open devtools:', error);
      }
    }
  });

  window.webContents.setWindowOpenHandler(() => ({
    action: 'deny',
  }));

  loadMainWindowContents(window).catch((error: unknown) => {
    log.error('Failed to load renderer:', error);
  });

  return window;
}

function setupIpcHandlers(): void {
  ipcMain.handle('secure-store:get-token', async () => getApiToken());
  ipcMain.handle('secure-store:set-token', async (_event, token: string | null) =>
    setApiToken(token)
  );

  ipcMain.handle('offline:get-cart', async () => loadCart());
  ipcMain.handle('offline:save-cart', async (_event, snapshot: CartSnapshot) =>
    saveCart(snapshot)
  );
  ipcMain.handle('offline:clear-cart', async () => clearCart());

  ipcMain.handle('offline:list-orders', async () => listQueuedOrders());
  ipcMain.handle('offline:queue-order', async (_event, order: OfflineOrder) =>
    queueOrder(order)
  );
  ipcMain.handle('offline:remove-order', async (_event, id: string) =>
    removeQueuedOrder(id)
  );

  ipcMain.handle('printer:list-devices', async () => listUsbPrinters());
  ipcMain.handle('printer:print-receipt', async (_event, payload: PrintReceiptPayload) =>
    printReceipt(payload)
  );

  ipcMain.handle('updater:check', async () => checkForUpdates());
  ipcMain.handle('updater:download', async () => downloadUpdate());
  ipcMain.handle('updater:quit-and-install', () => {
    quitAndInstall();
  });

  ipcMain.handle('app:reload', () => {
    mainWindow?.reload();
  });
}

function registerAppEvents(): void {
  app.on('browser-window-created', (_event, window) => {
    window.setMenu(null);
  });

  app.on('window-all-closed', () => {
    if (process.platform !== 'darwin') {
      app.quit();
    }
  });

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
      mainWindow = createWindow();
    }
  });
}

async function initialize(): Promise<void> {
  const gotLock = app.requestSingleInstanceLock();
  if (!gotLock) {
    app.quit();
    return;
  }

  app.on('second-instance', () => {
    if (mainWindow) {
      if (mainWindow.isMinimized()) {
        mainWindow.restore();
      }
      mainWindow.focus();
    }
  });

  await app.whenReady();

  const userDataPath = app.getPath('userData');
  initializeSecureStore(userDataPath);
  initializeOfflineStore(userDataPath);

  registerAppEvents();
  setupIpcHandlers();

  mainWindow = createWindow();

  if (mainWindow) {
    initializeAutoUpdater(mainWindow);
  }

  if (!isDev) {
    setTimeout(() => {
      void checkForUpdates().catch((error: unknown) => {
        log.error('Failed to check for updates:', error);
      });
    }, 5_000);
  }
}

initialize().catch((error: unknown) => {
  log.error('Failed to initialize application:', error);
  app.exit(1);
});
