"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.initializeAutoUpdater = initializeAutoUpdater;
exports.checkForUpdates = checkForUpdates;
exports.downloadUpdate = downloadUpdate;
exports.quitAndInstall = quitAndInstall;
const electron_updater_1 = require("electron-updater");
const electron_log_1 = __importDefault(require("electron-log"));
electron_updater_1.autoUpdater.autoDownload = false;
electron_updater_1.autoUpdater.logger = electron_log_1.default;
function initializeAutoUpdater(mainWindow) {
    electron_updater_1.autoUpdater.on('error', (error) => {
        electron_log_1.default.error('Auto-updater error:', error);
        mainWindow.webContents.send('updater:error', {
            message: error instanceof Error ? error.message : String(error),
        });
    });
    electron_updater_1.autoUpdater.on('update-available', (info) => {
        electron_log_1.default.info('Update available:', info.version);
        mainWindow.webContents.send('updater:update-available', info);
    });
    electron_updater_1.autoUpdater.on('update-not-available', (info) => {
        electron_log_1.default.info('Update not available');
        mainWindow.webContents.send('updater:update-not-available', info);
    });
    electron_updater_1.autoUpdater.on('download-progress', (progress) => {
        mainWindow.webContents.send('updater:download-progress', progress);
    });
    electron_updater_1.autoUpdater.on('update-downloaded', (info) => {
        electron_log_1.default.info('Update downloaded');
        mainWindow.webContents.send('updater:update-downloaded', info);
    });
}
async function checkForUpdates() {
    await electron_updater_1.autoUpdater.checkForUpdates();
}
async function downloadUpdate() {
    await electron_updater_1.autoUpdater.downloadUpdate();
}
function quitAndInstall() {
    electron_updater_1.autoUpdater.quitAndInstall();
}
