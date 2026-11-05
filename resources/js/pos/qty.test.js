import { describe, expect, it } from 'vitest';
import { digitsOnly, parseQty } from './qty.js';

describe('isian qty di tabel keranjang', () => {
    it('hanya menyisakan angka', () => {
        expect(digitsOnly('5 pcs')).toBe('5');
        expect(digitsOnly('-3')).toBe('3');
        expect(digitsOnly(null)).toBe('');
    });

    it('menerima bilangan bulat >= 1', () => {
        expect(parseQty('5')).toBe(5);
        expect(parseQty('012')).toBe(12);
    });

    it('mengabaikan isian kosong atau nol', () => {
        expect(parseQty('')).toBeNull();
        expect(parseQty('0')).toBeNull();
        expect(parseQty('abc')).toBeNull();
    });
});
