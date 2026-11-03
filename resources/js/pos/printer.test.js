import { describe, expect, it } from 'vitest';
import { receiptHtml } from './printer.js';

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
