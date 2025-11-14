import path from 'node:path';
import { promises as fs } from 'node:fs';
import keytar from 'keytar';
import log from 'electron-log';

const SERVICE = 'atlas-pos-desktop';
const ACCOUNT = 'api-token';

let fallbackFilePath: string | null = null;

export function initializeSecureStore(userDataPath: string): void {
  fallbackFilePath = path.join(userDataPath, 'secure-store.json');
}

async function readFallback(): Promise<string | null> {
  if (!fallbackFilePath) {
    throw new Error('Secure store not initialized');
  }

  try {
    const content = await fs.readFile(fallbackFilePath, 'utf-8');
    const data = JSON.parse(content) as { token?: string | null };
    return typeof data.token === 'string' ? data.token : null;
  } catch (error: unknown) {
    if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
      return null;
    }
    log.error('Secure store fallback read failed:', error);
    return null;
  }
}

async function writeFallback(token: string | null): Promise<void> {
  if (!fallbackFilePath) {
    throw new Error('Secure store not initialized');
  }

  const payload = JSON.stringify({ token }, null, 2);
  await fs.mkdir(path.dirname(fallbackFilePath), { recursive: true });
  await fs.writeFile(fallbackFilePath, payload, 'utf-8');
}

export async function getApiToken(): Promise<string | null> {
  try {
    const token = await keytar.getPassword(SERVICE, ACCOUNT);
    if (token) {
      return token;
    }
  } catch (error: unknown) {
    log.error('Secure store read failed:', error);
  }

  return readFallback();
}

export async function setApiToken(token: string | null): Promise<void> {
  try {
    if (token) {
      await keytar.setPassword(SERVICE, ACCOUNT, token);
    } else {
      await keytar.deletePassword(SERVICE, ACCOUNT);
    }
  } catch (error: unknown) {
    log.error('Secure store write failed:', error);
  }

  await writeFallback(token);
}
