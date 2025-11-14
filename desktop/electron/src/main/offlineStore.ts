import path from 'node:path';
import { promises as fs } from 'node:fs';

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

export type CartSnapshot = {
  lines: CartLine[];
  discount: number;
};

export type OfflineOrder = {
  id: string;
  createdAt: string;
  payload: unknown;
  retries?: number;
  lastTriedAt?: string | null;
};

let offlineDirPath: string | null = null;
let cartFilePath: string | null = null;
let queueFilePath: string | null = null;

export function initializeOfflineStore(userDataPath: string): void {
  offlineDirPath = path.join(userDataPath, 'offline');
  cartFilePath = path.join(offlineDirPath, 'cart.json');
  queueFilePath = path.join(offlineDirPath, 'orders.json');
}

async function ensureInitialized(): Promise<void> {
  if (!offlineDirPath || !cartFilePath || !queueFilePath) {
    throw new Error('Offline store not initialized');
  }

  await fs.mkdir(offlineDirPath, { recursive: true });
}

async function readJsonFile<T>(filePath: string, fallback: T): Promise<T> {
  try {
    const content = await fs.readFile(filePath, 'utf-8');
    return JSON.parse(content) as T;
  } catch (error: unknown) {
    if ((error as NodeJS.ErrnoException).code === 'ENOENT') {
      return fallback;
    }
    throw error;
  }
}

async function writeJsonFile<T>(filePath: string, data: T): Promise<void> {
  const payload = JSON.stringify(data, null, 2);
  await fs.writeFile(filePath, payload, 'utf-8');
}

let writeQueue = Promise.resolve();

async function enqueueWrite<T>(filePath: string, data: T): Promise<void> {
  writeQueue = writeQueue.then(() => writeJsonFile(filePath, data));
  await writeQueue;
}

export async function loadCart(): Promise<CartSnapshot> {
  await ensureInitialized();
  const defaultValue: CartSnapshot = { lines: [], discount: 0 };
  return readJsonFile<CartSnapshot>(cartFilePath!, defaultValue);
}

export async function saveCart(snapshot: CartSnapshot): Promise<void> {
  await ensureInitialized();
  await enqueueWrite(cartFilePath!, snapshot);
}

export async function clearCart(): Promise<void> {
  await ensureInitialized();
  await enqueueWrite(cartFilePath!, { lines: [], discount: 0 });
}

export async function listQueuedOrders(): Promise<OfflineOrder[]> {
  await ensureInitialized();
  return readJsonFile<OfflineOrder[]>(queueFilePath!, []);
}

export async function queueOrder(order: OfflineOrder): Promise<void> {
  await ensureInitialized();
  const orders = await listQueuedOrders();
  const existingIndex = orders.findIndex((item) => item.id === order.id);
  if (existingIndex >= 0) {
    orders[existingIndex] = order;
  } else {
    orders.push(order);
  }
  await enqueueWrite(queueFilePath!, orders);
}

export async function removeQueuedOrder(id: string): Promise<void> {
  await ensureInitialized();
  const orders = await listQueuedOrders();
  const filtered = orders.filter((item) => item.id !== id);
  await enqueueWrite(queueFilePath!, filtered);
}

export async function clearQueuedOrders(): Promise<void> {
  await ensureInitialized();
  await enqueueWrite(queueFilePath!, []);
}
