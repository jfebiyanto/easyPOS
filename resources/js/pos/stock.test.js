import { describe, expect, it } from 'vitest';
import { fitCartToStock, isOutOfStock, maxQty } from './stock.js';

describe('isOutOfStock', () => {
    it('menandai produk berstok terlacak yang habis', () => {
        expect(isOutOfStock({ track_stock: true, stock: 0 })).toBe(true);
        expect(isOutOfStock({ track_stock: true, stock: -2 })).toBe(true);
        expect(isOutOfStock({ track_stock: true, stock: 3 })).toBe(false);
    });

    it('tidak membatasi produk tanpa pelacakan stok atau bila stok negatif diizinkan', () => {
        expect(isOutOfStock({ track_stock: false, stock: null })).toBe(false);
        expect(isOutOfStock({ track_stock: true, stock: 0 }, true)).toBe(false);
    });
});

describe('maxQty', () => {
    it('membatasi qty sesuai sisa stok produk berstok terlacak', () => {
        expect(maxQty({ track_stock: true, stock: 3 })).toBe(3);
        expect(maxQty({ track_stock: true, stock: -1 })).toBe(0);
        expect(maxQty({ track_stock: false, stock: null })).toBe(Infinity);
        expect(maxQty({ track_stock: true, stock: 3 }, true)).toBe(Infinity);
    });
});

describe('fitCartToStock', () => {
    const cart = [
        { id: 'a', name: 'Aqua', qty: 5, track_stock: true, stock: 10 },
        { id: 'b', name: 'Teh', qty: 2, track_stock: true, stock: 10 },
        { id: 'c', name: 'Jasa', qty: 1, track_stock: false, stock: null },
    ];

    it('memotong qty ke stok terkini dan membuang item yang stoknya habis', () => {
        const { lines, adjustments } = fitCartToStock(cart, { a: 3, b: 0, c: null });

        expect(lines.map((l) => [l.id, l.qty, l.stock])).toEqual([['a', 3, 3], ['c', 1, null]]);
        expect(adjustments).toEqual([{ name: 'Aqua', from: 5, to: 3 }, { name: 'Teh', from: 2, to: 0 }]);
    });

    it('tidak mengubah keranjang bila stok negatif diizinkan', () => {
        const { lines, adjustments } = fitCartToStock(cart, { a: 3, b: 0, c: null }, true);

        expect(lines.map((l) => l.qty)).toEqual([5, 2, 1]);
        expect(adjustments).toEqual([]);
    });
});
