"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
const electron_1 = require("electron");
function registerEvent(channel, callback) {
    const listener = (_event, data) => {
        callback(data);
    };
    electron_1.ipcRenderer.on(channel, listener);
    return () => {
        electron_1.ipcRenderer.removeListener(channel, listener);
    };
}
const atlasDesktop = {
    platform: process.platform,
    isDesktop: true,
    secureStore: {
        getToken: () => electron_1.ipcRenderer.invoke('secure-store:get-token'),
        setToken: (token) => electron_1.ipcRenderer.invoke('secure-store:set-token', token),
    },
    offline: {
        getCart: () => electron_1.ipcRenderer.invoke('offline:get-cart'),
        saveCart: (snapshot) => electron_1.ipcRenderer.invoke('offline:save-cart', snapshot),
        clearCart: () => electron_1.ipcRenderer.invoke('offline:clear-cart'),
        listOrders: () => electron_1.ipcRenderer.invoke('offline:list-orders'),
        queueOrder: (order) => electron_1.ipcRenderer.invoke('offline:queue-order', order),
        removeOrder: (id) => electron_1.ipcRenderer.invoke('offline:remove-order', id),
    },
    printer: {
        listDevices: () => electron_1.ipcRenderer.invoke('printer:list-devices'),
        printReceipt: (payload) => electron_1.ipcRenderer.invoke('printer:print-receipt', payload),
    },
    updater: {
        check: () => electron_1.ipcRenderer.invoke('updater:check'),
        download: () => electron_1.ipcRenderer.invoke('updater:download'),
        quitAndInstall: () => electron_1.ipcRenderer.invoke('updater:quit-and-install'),
        onUpdateAvailable: (listener) => registerEvent('updater:update-available', listener),
        onUpdateNotAvailable: (listener) => registerEvent('updater:update-not-available', listener),
        onDownloadProgress: (listener) => registerEvent('updater:download-progress', listener),
        onUpdateDownloaded: (listener) => registerEvent('updater:update-downloaded', listener),
        onError: (listener) => registerEvent('updater:error', listener),
    },
    app: {
        reload: () => electron_1.ipcRenderer.invoke('app:reload'),
    },
};
electron_1.contextBridge.exposeInMainWorld('atlasDesktop', atlasDesktop);
