let usbDevice = null;

const money = (n, currency = 'IDR') =>
    `${currency === 'IDR' ? 'Rp ' : currency + ' '}${Number(n || 0).toLocaleString('id-ID', { minimumFractionDigits: 0 })}`;

export function receiptHtml(sale, meta) {
    const lines = (sale.lines || [])
        .map(
            (l) => `
        <tr>
            <td colspan="3">${l.name}</td>
        </tr>
        <tr>
            <td>${l.qty} x ${money(l.unit_price)}</td>
            <td style="text-align:right">${money(l.line_total)}</td>
            <td></td>
        </tr>${
            l.discount > 0
                ? `
        <tr>
            <td>&nbsp;&nbsp;${l.promotion_name || 'Diskon'}</td>
            <td style="text-align:right">-${money(l.discount)}</td>
            <td></td>
        </tr>`
                : ''
        }`
        )
        .join('');

    const saving = sale.discount_total > 0
        ? `<tr><td>Hemat</td><td style="text-align:right">-${money(sale.discount_total, sale.currency)}</td></tr>`
        : '';

    const delivery = sale.delivery
        ? `<div class="small">Kirim ke: ${sale.delivery.recipient_name}<br>${sale.delivery.address}</div>`
        : '';

    const pendingNote =
        sale.has_pending_payment || sale.origin === 'offline'
            ? `<div class="pending">** BELUM TERKONFIRMASI **</div>`
            : '';

    return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Struk ${sale.number}</title>
    <style>
        * { font-family: 'IBM Plex Mono', monospace; }
        body { width: 72mm; margin: 0 auto; font-size: 12px; color: #000; }
        h1 { font-size: 15px; text-align: center; margin: 4px 0; }
        .center { text-align: center; }
        .small { font-size: 10px; }
        .pending { border: 1px solid #000; padding: 4px; text-align: center; margin: 6px 0; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        td { font-size: 11px; padding: 1px 0; vertical-align: top; }
        .total { font-size: 14px; font-weight: bold; border-top: 1px dashed #000; padding-top: 4px; }
        hr { border: none; border-top: 1px dashed #000; }
    </style></head><body>
        <h1>${meta.company || 'easyPOS'}</h1>
        <div class="center small">${meta.store || ''}</div>
        <div class="center small">${meta.address || ''}</div>
        <hr>
        <div class="small">No: ${sale.number}<br>Kasir: ${meta.cashier || ''}<br>Waktu: ${new Date(sale.sold_at || Date.now()).toLocaleString('id-ID')}</div>
        <hr>
        <table>${lines}</table>
        <hr>
        <table>
            ${saving}
            <tr><td>Subtotal</td><td style="text-align:right">${money(sale.subtotal, sale.currency)}</td></tr>
            <tr><td>PPN</td><td style="text-align:right">${money(sale.tax_total, sale.currency)}</td></tr>
            ${sale.shipping_cost ? `<tr><td>Kirim</td><td style="text-align:right">${money(sale.shipping_cost, sale.currency)}</td></tr>` : ''}
            <tr class="total"><td>TOTAL</td><td style="text-align:right">${money(sale.total, sale.currency)}</td></tr>
            <tr><td>Bayar</td><td style="text-align:right">${money(sale.paid_total, sale.currency)}</td></tr>
            <tr><td>Kembali</td><td style="text-align:right">${money(sale.change_total, sale.currency)}</td></tr>
        </table>
        ${delivery}
        ${pendingNote}
        <hr>
        <div class="center small">Terima kasih</div>
        <script>window.onload = function () { window.print(); setTimeout(function(){ window.close(); }, 400); };</script>
    </body></html>`;
}

/**
 * Cetak struk. Prioritas: WebUSB (bila tersambung) → fallback HTML print.
 */
export async function printReceipt(sale, meta) {
    if (usbDevice) {
        const ok = await usbPrint(sale, meta);
        if (ok) return 'usb';
    }

    const win = window.open('', '_blank', 'width=380,height=640');
    if (!win) return 'blocked';
    win.document.write(receiptHtml(sale, meta));
    win.document.close();
    return 'html';
}

export async function connectUsbPrinter() {
    if (!navigator.usb) {
        throw new Error('Browser tidak mendukung WebUSB. Gunakan cetak HTML.');
    }
    usbDevice = await navigator.usb.requestDevice({ filters: [{ classCode: 7 }] });
    await usbDevice.open();
    if (usbDevice.configuration === null) {
        await usbDevice.selectConfiguration(1);
    }
    await usbDevice.claimInterface(0);
    return usbDevice.productName || 'Printer USB';
}

export function hasUsbPrinter() {
    return !!usbDevice;
}

async function usbPrint(sale, meta) {
    try {
        const enc = new TextEncoder();
        const text =
            `${meta.company || 'easyPOS'}\n` +
            `No: ${sale.number}\n` +
            (sale.discount_total > 0 ? `Hemat: -${money(sale.discount_total, sale.currency)}\n` : '') +
            `TOTAL: ${money(sale.total, sale.currency)}\n` +
            `Bayar: ${money(sale.paid_total, sale.currency)}\n` +
            `Kembali: ${money(sale.change_total, sale.currency)}\n` +
            `\n\n\n`;
        const data = enc.encode(text + '\x1D\x56\x00'); // ESC/POS cut
        const iface = usbDevice.configuration.interfaces[0];
        const endpoint = iface.alternate.endpoints.find((e) => e.direction === 'out');
        await usbDevice.transferOut(endpoint.endpointNumber, data);
        return true;
    } catch {
        return false;
    }
}
