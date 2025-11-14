"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.initializeOfflineStore = initializeOfflineStore;
exports.loadCart = loadCart;
exports.saveCart = saveCart;
exports.clearCart = clearCart;
exports.listQueuedOrders = listQueuedOrders;
exports.queueOrder = queueOrder;
exports.removeQueuedOrder = removeQueuedOrder;
exports.clearQueuedOrders = clearQueuedOrders;
const node_path_1 = __importDefault(require("node:path"));
const node_fs_1 = require("node:fs");
let offlineDirPath = null;
let cartFilePath = null;
let queueFilePath = null;
function initializeOfflineStore(userDataPath) {
    offlineDirPath = node_path_1.default.join(userDataPath, 'offline');
    cartFilePath = node_path_1.default.join(offlineDirPath, 'cart.json');
    queueFilePath = node_path_1.default.join(offlineDirPath, 'orders.json');
}
async function ensureInitialized() {
    if (!offlineDirPath || !cartFilePath || !queueFilePath) {
        throw new Error('Offline store not initialized');
    }
    await node_fs_1.promises.mkdir(offlineDirPath, { recursive: true });
}
async function readJsonFile(filePath, fallback) {
    try {
        const content = await node_fs_1.promises.readFile(filePath, 'utf-8');
        return JSON.parse(content);
    }
    catch (error) {
        if (error.code === 'ENOENT') {
            return fallback;
        }
        throw error;
    }
}
async function writeJsonFile(filePath, data) {
    const payload = JSON.stringify(data, null, 2);
    await node_fs_1.promises.writeFile(filePath, payload, 'utf-8');
}
let writeQueue = Promise.resolve();
async function enqueueWrite(filePath, data) {
    writeQueue = writeQueue.then(() => writeJsonFile(filePath, data));
    await writeQueue;
}
async function loadCart() {
    await ensureInitialized();
    const defaultValue = { lines: [], discount: 0 };
    return readJsonFile(cartFilePath, defaultValue);
}
async function saveCart(snapshot) {
    await ensureInitialized();
    await enqueueWrite(cartFilePath, snapshot);
}
async function clearCart() {
    await ensureInitialized();
    await enqueueWrite(cartFilePath, { lines: [], discount: 0 });
}
async function listQueuedOrders() {
    await ensureInitialized();
    return readJsonFile(queueFilePath, []);
}
async function queueOrder(order) {
    await ensureInitialized();
    const orders = await listQueuedOrders();
    const existingIndex = orders.findIndex((item) => item.id === order.id);
    if (existingIndex >= 0) {
        orders[existingIndex] = order;
    }
    else {
        orders.push(order);
    }
    await enqueueWrite(queueFilePath, orders);
}
async function removeQueuedOrder(id) {
    await ensureInitialized();
    const orders = await listQueuedOrders();
    const filtered = orders.filter((item) => item.id !== id);
    await enqueueWrite(queueFilePath, filtered);
}
async function clearQueuedOrders() {
    await ensureInitialized();
    await enqueueWrite(queueFilePath, []);
}
