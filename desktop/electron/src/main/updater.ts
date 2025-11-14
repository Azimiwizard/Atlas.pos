import type { BrowserWindow } from 'electron';
import { autoUpdater } from 'electron-updater';
import log from 'electron-log';

autoUpdater.autoDownload = false;
autoUpdater.logger = log;

export function initializeAutoUpdater(mainWindow: BrowserWindow): void {
  autoUpdater.on('error', (error) => {
    log.error('Auto-updater error:', error);
    mainWindow.webContents.send('updater:error', {
      message: error instanceof Error ? error.message : String(error),
    });
  });

  autoUpdater.on('update-available', (info) => {
    log.info('Update available:', info.version);
    mainWindow.webContents.send('updater:update-available', info);
  });

  autoUpdater.on('update-not-available', (info) => {
    log.info('Update not available');
    mainWindow.webContents.send('updater:update-not-available', info);
  });

  autoUpdater.on('download-progress', (progress) => {
    mainWindow.webContents.send('updater:download-progress', progress);
  });

  autoUpdater.on('update-downloaded', (info) => {
    log.info('Update downloaded');
    mainWindow.webContents.send('updater:update-downloaded', info);
  });
}

export async function checkForUpdates(): Promise<void> {
  await autoUpdater.checkForUpdates();
}

export async function downloadUpdate(): Promise<void> {
  await autoUpdater.downloadUpdate();
}

export function quitAndInstall(): void {
  autoUpdater.quitAndInstall();
}
