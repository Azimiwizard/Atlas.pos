"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.listUsbPrinters = listUsbPrinters;
exports.printReceipt = printReceipt;
/* eslint-disable @typescript-eslint/no-var-requires */
const dayjs_1 = __importDefault(require("dayjs"));
const escpos = require('escpos');
const UsbAdapter = require('escpos-usb');
escpos.USB = UsbAdapter;
function formatValue(value) {
    return value.toFixed(2);
}
function formatColumns(left, right, width = 32) {
    const sanitizedLeft = left.length > width ? left.slice(0, width) : left;
    const sanitizedRight = right.length > width ? right.slice(0, width) : right;
    const space = width - sanitizedLeft.length - sanitizedRight.length;
    if (space <= 0) {
        return `${sanitizedLeft}\n${sanitizedRight}`;
    }
    return `${sanitizedLeft}${' '.repeat(space)}${sanitizedRight}`;
}
function listUsbPrinters() {
    const devices = UsbAdapter.findPrinter();
    return devices.map((device) => ({
        type: 'usb',
        vendorId: device.deviceDescriptor?.idVendor ?? 0,
        productId: device.deviceDescriptor?.idProduct ?? 0,
        deviceAddress: device.deviceAddress,
        serialNumber: device.serialNumber ?? null,
    }));
}
async function printReceipt(payload) {
    if (!payload.items || payload.items.length === 0) {
        throw new Error('Receipt items are required for printing.');
    }
    const device = payload.device && payload.device.vendorId && payload.device.productId
        ? new escpos.USB(payload.device.vendorId, payload.device.productId)
        : new escpos.USB();
    const printer = new escpos.Printer(device, {
        encoding: 'GB18030',
    });
    await new Promise((resolve, reject) => {
        device.open((openError) => {
            if (openError) {
                reject(openError);
                return;
            }
            try {
                const { metadata } = payload;
                const printedAt = metadata?.printedAt
                    ? (0, dayjs_1.default)(metadata.printedAt).format('YYYY-MM-DD HH:mm')
                    : (0, dayjs_1.default)().format('YYYY-MM-DD HH:mm');
                printer
                    .align('CT')
                    .style('B')
                    .text(metadata?.storeName ?? 'Atlas POS')
                    .style('NORMAL');
                if (metadata?.storeCode) {
                    printer.text(`Store: ${metadata.storeCode}`);
                }
                printer.text(printedAt);
                if (metadata?.orderNumber) {
                    printer.text(`Order #: ${metadata.orderNumber}`);
                }
                else if (metadata?.orderId) {
                    printer.text(`Order ID: ${metadata.orderId}`);
                }
                if (metadata?.cashier) {
                    printer.text(`Cashier: ${metadata.cashier}`);
                }
                printer.text('-'.repeat(32));
                payload.items.forEach((item) => {
                    const quantityLabel = `${item.qty} x ${formatValue(item.unitPrice)}`;
                    printer
                        .align('LT')
                        .text(item.name)
                        .text(formatColumns(quantityLabel, formatValue(item.total)));
                });
                printer.text('-'.repeat(32));
                printer
                    .align('LT')
                    .text(formatColumns('Subtotal', formatValue(payload.totals.subtotal)))
                    .text(formatColumns('Discount', `-${formatValue(payload.totals.discount)}`))
                    .text(formatColumns('Tax', formatValue(payload.totals.tax)))
                    .style('B')
                    .text(formatColumns('TOTAL', formatValue(payload.totals.total)))
                    .style('NORMAL');
                if (typeof payload.totals.refunded === 'number' && payload.totals.refunded > 0) {
                    printer.text(formatColumns('Refunded', `-${formatValue(payload.totals.refunded)}`));
                }
                if (typeof payload.totals.net === 'number') {
                    printer.text(formatColumns('Net Total', formatValue(payload.totals.net)));
                }
                if (payload.footer && payload.footer.length > 0) {
                    printer.feed(1).align('CT');
                    payload.footer.forEach((line) => printer.text(line));
                }
                else {
                    printer.feed(1).align('CT').text('Thank you!');
                }
                printer.feed(3).cut().close();
                resolve();
            }
            catch (error) {
                reject(error);
            }
        });
    });
}
