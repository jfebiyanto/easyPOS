import { afterEach, describe, expect, it } from 'vitest';
import { buildCartPayload, clearCartBackup, isEmptyCart, pickNewestCart, readCartBackup, writeCartBackup } from './cartPersistence.js';

const payload = (savedAt, qty = 1) => ({ cart: [{ id: 'p', qty }], saved_at: savedAt });

describe('pemulihan keranjang', () => {
    it('memilih keranjang terbaru antara server dan cadangan browser', () => {
        const server = payload('2026-11-05T10:00:00.000Z', 3);
        const local = payload('2026-11-05T10:00:02.000Z', 4);

        expect(pickNewestCart(server, local).cart[0].qty).toBe(4);
        expect(pickNewestCart(local, server).cart[0].qty).toBe(4);
    });

    it('memakai satu-satunya keranjang yang ada dan mengabaikan keranjang kosong', () => {
        const server = payload('2026-11-05T10:00:00.000Z', 3);

        expect(pickNewestCart(server, null)).toBe(server);
        expect(pickNewestCart(null, server)).toBe(server);
        expect(pickNewestCart({ cart: [], saved_at: '2026-11-05T11:00:00.000Z' }, server)).toBe(server);
        expect(pickNewestCart(null, null)).toBeNull();
        expect(pickNewestCart({ cart: [] }, undefined)).toBeNull();
    });

    it('menyusun payload keranjang dengan waktu simpan', () => {
        const built = buildCartPayload({ cart: [{ id: 'p' }], delivery: { enabled: false }, customer: undefined, activeHold: undefined }, new Date('2026-11-05T10:00:00Z'));

        expect(built).toEqual({ cart: [{ id: 'p' }], delivery: { enabled: false }, customer: null, active_hold: null, saved_at: '2026-11-05T10:00:00.000Z' });
        expect(isEmptyCart(built)).toBe(false);
        expect(isEmptyCart({ cart: [] })).toBe(true);
    });
});

describe('cadangan keranjang di browser', () => {
    afterEach(() => {
        delete globalThis.localStorage;
    });

    it('tidak error bila penyimpanan browser tidak tersedia', () => {
        expect(() => writeCartBackup('u1', payload('2026-11-05T10:00:00Z'))).not.toThrow();
        expect(readCartBackup('u1')).toBeNull();
        expect(() => clearCartBackup()).not.toThrow();
    });

    it('hanya mengembalikan cadangan milik kasir yang sama', () => {
        const store = {};
        globalThis.localStorage = { getItem: (k) => store[k] ?? null, setItem: (k, v) => { store[k] = v; }, removeItem: (k) => { delete store[k]; } };
        const saved = payload('2026-11-05T10:00:00Z', 2);

        writeCartBackup('u1', saved);
        expect(readCartBackup('u1')).toEqual(saved);
        expect(readCartBackup('u2')).toBeNull();

        clearCartBackup();
        expect(readCartBackup('u1')).toBeNull();
    });
});
