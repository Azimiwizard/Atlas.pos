"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.initializeSecureStore = initializeSecureStore;
exports.getApiToken = getApiToken;
exports.setApiToken = setApiToken;
const node_path_1 = __importDefault(require("node:path"));
const node_fs_1 = require("node:fs");
const keytar_1 = __importDefault(require("keytar"));
const electron_log_1 = __importDefault(require("electron-log"));
const SERVICE = 'atlas-pos-desktop';
const ACCOUNT = 'api-token';
let fallbackFilePath = null;
function initializeSecureStore(userDataPath) {
    fallbackFilePath = node_path_1.default.join(userDataPath, 'secure-store.json');
}
async function readFallback() {
    if (!fallbackFilePath) {
        throw new Error('Secure store not initialized');
    }
    try {
        const content = await node_fs_1.promises.readFile(fallbackFilePath, 'utf-8');
        const data = JSON.parse(content);
        return typeof data.token === 'string' ? data.token : null;
    }
    catch (error) {
        if (error.code === 'ENOENT') {
            return null;
        }
        electron_log_1.default.error('Secure store fallback read failed:', error);
        return null;
    }
}
async function writeFallback(token) {
    if (!fallbackFilePath) {
        throw new Error('Secure store not initialized');
    }
    const payload = JSON.stringify({ token }, null, 2);
    await node_fs_1.promises.mkdir(node_path_1.default.dirname(fallbackFilePath), { recursive: true });
    await node_fs_1.promises.writeFile(fallbackFilePath, payload, 'utf-8');
}
async function getApiToken() {
    try {
        const token = await keytar_1.default.getPassword(SERVICE, ACCOUNT);
        if (token) {
            return token;
        }
    }
    catch (error) {
        electron_log_1.default.error('Secure store read failed:', error);
    }
    return readFallback();
}
async function setApiToken(token) {
    try {
        if (token) {
            await keytar_1.default.setPassword(SERVICE, ACCOUNT, token);
        }
        else {
            await keytar_1.default.deletePassword(SERVICE, ACCOUNT);
        }
    }
    catch (error) {
        electron_log_1.default.error('Secure store write failed:', error);
    }
    await writeFallback(token);
}
