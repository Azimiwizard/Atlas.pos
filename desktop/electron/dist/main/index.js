"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const electron_1 = require("electron");
const node_path_1 = __importDefault(require("node:path"));
const electron_log_1 = __importDefault(require("electron-log"));
const secureStore_1 = require("./secureStore");
const offlineStore_1 = require("./offlineStore");
const printer_1 = require("./printer");
const updater_1 = require("./updater");
const isDev = process.env.NODE_ENV === 'development' || !electron_1.app.isPackaged;
let mainWindow = null;
process.env.ELECTRON_DISABLE_SECURITY_WARNINGS = 'true';
function getPreloadPath() {
    return node_path_1.default.join(__dirname, '..', 'preload', 'index.js');
}
function getProdIndexPath() {
    return node_path_1.default.join(process.resourcesPath, 'web', 'index.html');
}
async function loadMainWindowContents(window) {
    if (isDev) {
        const devServerUrl = process.env.ELECTRON_START_URL ?? 'http://localhost:5173';
        await window.loadURL(devServerUrl);
    }
    else {
        const indexPath = getProdIndexPath();
        await window.loadFile(indexPath);
    }
}
function createWindow() {
    const window = new electron_1.BrowserWindow({
        width: 1280,
        height: 800,
        show: false,
        fullscreen: true,
        kiosk: true,
        autoHideMenuBar: true,
        backgroundColor: electron_1.nativeTheme.shouldUseDarkColors ? '#111111' : '#ffffff',
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
            }
            catch (error) {
                electron_log_1.default.warn('Failed to open devtools:', error);
            }
        }
    });
    window.webContents.setWindowOpenHandler(() => ({
        action: 'deny',
    }));
    loadMainWindowContents(window).catch((error) => {
        electron_log_1.default.error('Failed to load renderer:', error);
    });
    return window;
}
function setupIpcHandlers() {
    electron_1.ipcMain.handle('secure-store:get-token', async () => (0, secureStore_1.getApiToken)());
    electron_1.ipcMain.handle('secure-store:set-token', async (_event, token) => (0, secureStore_1.setApiToken)(token));
    electron_1.ipcMain.handle('offline:get-cart', async () => (0, offlineStore_1.loadCart)());
    electron_1.ipcMain.handle('offline:save-cart', async (_event, snapshot) => (0, offlineStore_1.saveCart)(snapshot));
    electron_1.ipcMain.handle('offline:clear-cart', async () => (0, offlineStore_1.clearCart)());
    electron_1.ipcMain.handle('offline:list-orders', async () => (0, offlineStore_1.listQueuedOrders)());
    electron_1.ipcMain.handle('offline:queue-order', async (_event, order) => (0, offlineStore_1.queueOrder)(order));
    electron_1.ipcMain.handle('offline:remove-order', async (_event, id) => (0, offlineStore_1.removeQueuedOrder)(id));
    electron_1.ipcMain.handle('printer:list-devices', async () => (0, printer_1.listUsbPrinters)());
    electron_1.ipcMain.handle('printer:print-receipt', async (_event, payload) => (0, printer_1.printReceipt)(payload));
    electron_1.ipcMain.handle('updater:check', async () => (0, updater_1.checkForUpdates)());
    electron_1.ipcMain.handle('updater:download', async () => (0, updater_1.downloadUpdate)());
    electron_1.ipcMain.handle('updater:quit-and-install', () => {
        (0, updater_1.quitAndInstall)();
    });
    electron_1.ipcMain.handle('app:reload', () => {
        mainWindow?.reload();
    });
}
function registerAppEvents() {
    electron_1.app.on('browser-window-created', (_event, window) => {
        window.setMenu(null);
    });
    electron_1.app.on('window-all-closed', () => {
        if (process.platform !== 'darwin') {
            electron_1.app.quit();
        }
    });
    electron_1.app.on('activate', () => {
        if (electron_1.BrowserWindow.getAllWindows().length === 0) {
            mainWindow = createWindow();
        }
    });
}
async function initialize() {
    const gotLock = electron_1.app.requestSingleInstanceLock();
    if (!gotLock) {
        electron_1.app.quit();
        return;
    }
    electron_1.app.on('second-instance', () => {
        if (mainWindow) {
            if (mainWindow.isMinimized()) {
                mainWindow.restore();
            }
            mainWindow.focus();
        }
    });
    await electron_1.app.whenReady();
    const userDataPath = electron_1.app.getPath('userData');
    (0, secureStore_1.initializeSecureStore)(userDataPath);
    (0, offlineStore_1.initializeOfflineStore)(userDataPath);
    registerAppEvents();
    setupIpcHandlers();
    mainWindow = createWindow();
    if (mainWindow) {
        (0, updater_1.initializeAutoUpdater)(mainWindow);
    }
    if (!isDev) {
        setTimeout(() => {
            void (0, updater_1.checkForUpdates)().catch((error) => {
                electron_log_1.default.error('Failed to check for updates:', error);
            });
        }, 5_000);
    }
}
initialize().catch((error) => {
    electron_log_1.default.error('Failed to initialize application:', error);
    electron_1.app.exit(1);
});
