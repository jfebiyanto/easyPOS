import { describe, expect, it } from 'vitest';
import { FUNCTION_KEYS, functionKeyStates, holdOrRecallAction, isFunctionKey, keyToAction, moveSelection, selectionAfterRemoval } from './functionKeys.js';

const state = (overrides) => Object.fromEntries(
    functionKeyStates({ cartEmpty: false, modalOpen: false, ...overrides }).map((k) => [k.key, k]),
);

describe('functionKeyStates', () => {
    it('menyediakan F1 sampai F12 berurutan dan F4 tanpa fungsi', () => {
        expect(FUNCTION_KEYS.map((k) => k.key)).toEqual(['F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7', 'F8', 'F9', 'F10', 'F11', 'F12']);
        expect(state({}).F4.disabled).toBe(true);
    });

    it('keranjang kosong menonaktifkan F5, F8, F9, F11, dan F12 saja', () => {
        const disabled = Object.values(state({ cartEmpty: true })).filter((k) => k.disabled).map((k) => k.key);

        expect(disabled).toEqual(['F4', 'F5', 'F8', 'F9', 'F11', 'F12']);
    });

    it('semua tombol nonaktif saat dialog terbuka', () => {
        expect(Object.values(state({ modalOpen: true })).every((k) => k.disabled)).toBe(true);
    });

    it('F11 nonaktif bila gerai tidak punya metode tunai', () => {
        expect(state({ hasCashMethod: false }).F11.disabled).toBe(true);
        expect(state({ hasCashMethod: false }).F12.disabled).toBe(false);
    });

    it('F10 menampilkan jumlah transaksi ditahan sebagai badge', () => {
        expect(state({ heldCount: 3 }).F10.badge).toBe(3);
        expect(state({ heldCount: 0 }).F10.badge).toBeNull();
    });
});

describe('F10 tahan atau daftar', () => {
    it('menahan bila keranjang berisi dan membuka daftar bila kosong', () => {
        expect(holdOrRecallAction(false)).toBe('hold');
        expect(holdOrRecallAction(true)).toBe('recall');
    });
});

describe('keyToAction', () => {
    it('memetakan tombol F ke aksi dan mengabaikan kombinasi modifier', () => {
        expect(keyToAction({ key: 'F12' })).toBe('pay');
        expect(keyToAction({ key: 'F2' })).toBe('customer');
        expect(keyToAction({ key: 'F5', ctrlKey: true })).toBeNull();
        expect(keyToAction({ key: 'a' })).toBeNull();
    });

    it('mengenali semua tombol F1–F12 untuk dicegat dari fungsi bawaan browser, termasuk F4 tanpa aksi', () => {
        for (let i = 1; i <= 12; i += 1) {
            expect(isFunctionKey({ key: `F${i}` })).toBe(true);
        }
        expect(keyToAction({ key: 'F4' })).toBeNull();
        expect(isFunctionKey({ key: 'F4' })).toBe(true);
        expect(isFunctionKey({ key: 'F13' })).toBe(false);
        expect(isFunctionKey({ key: 'F5', altKey: true })).toBe(false);
    });
});

describe('pemilihan baris', () => {
    it('berpindah dengan panah dan berhenti di ujung tabel', () => {
        expect(moveSelection(0, -1, 3)).toBe(0);
        expect(moveSelection(1, 1, 3)).toBe(2);
        expect(moveSelection(2, 1, 3)).toBe(2);
    });

    it('tanpa pilihan: panah bawah memilih baris pertama, panah atas baris terakhir, tabel kosong tanpa pilihan', () => {
        expect(moveSelection(-1, 1, 3)).toBe(0);
        expect(moveSelection(-1, -1, 3)).toBe(2);
        expect(moveSelection(-1, 1, 0)).toBe(-1);
    });

    it('setelah hapus memilih baris di bawahnya atau yang terakhir', () => {
        expect(selectionAfterRemoval(1, 2)).toBe(1);
        expect(selectionAfterRemoval(2, 2)).toBe(1);
        expect(selectionAfterRemoval(0, 0)).toBe(-1);
    });
});
