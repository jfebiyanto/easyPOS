import { describe, expect, it } from 'vitest';
import { getAutoPrint, receiptHtml, setAutoPrint } from './printer.js';
import { afterEach } from 'vitest';

const sale = (overrides = {}) => ({
    number: 'INV-1',
    currency: 'IDR',
    subtotal: 40000,
    tax_total: 0,
    total: 40000,
    paid_total: 40000,
    change_total: 0,
    discount_total: 0,
    lines: [{ name: 'Kopi Susu', qty: 3, unit_price: 20000, discount: 0, line_total: 60000 }],
    ...overrides,
});

describe('receiptHtml', () => {
    it('menampilkan baris promosi dan total hemat bila ada diskon', () => {
        const html = receiptHtml(
            sale({
                discount_total: 20000,
                lines: [{ name: 'Kopi Susu', qty: 3, unit_price: 20000, discount: 20000, promotion_name: 'Beli 2 Gratis 1', line_total: 40000 }],
            }),
            {},
        );

        expect(html).toContain('Beli 2 Gratis 1');
        expect(html).toContain('-Rp 20.000');
        expect(html).toContain('Hemat');
    });

    it('tidak menampilkan baris promosi bila tidak ada diskon', () => {
        const html = receiptHtml(sale(), {});

        expect(html).not.toContain('Hemat');
        expect(html).not.toContain('Diskon');
    });
});

describe('cetak struk tanpa jendela popup', () => {
    it('versi iframe tidak membawa skrip cetak sendiri, versi jendela membawanya', () => {
        expect(receiptHtml(sale(), {}, { embedded: true })).not.toContain('window.print');
        expect(receiptHtml(sale(), {})).toContain('window.print');
    });
});

describe('pengaturan cetak otomatis', () => {
    afterEach(() => {
        delete globalThis.localStorage;
    });

    it('aktif bawaan, termasuk saat penyimpanan browser tidak tersedia', () => {
        expect(getAutoPrint()).toBe(true);
        expect(() => setAutoPrint(false)).not.toThrow();
    });

    it('menyimpan pilihan kasir per perangkat', () => {
        const store = {};
        globalThis.localStorage = { getItem: (k) => store[k] ?? null, setItem: (k, v) => { store[k] = v; } };

        setAutoPrint(false);
        expect(getAutoPrint()).toBe(false);
        setAutoPrint(true);
        expect(getAutoPrint()).toBe(true);
    });
});
