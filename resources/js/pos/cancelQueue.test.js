import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enqueueCancellation, flushCancelQueue, readCancelQueue } from './cancelQueue.js';

const httpError = (status) => Object.assign(new Error('gagal'), { status });

describe('antrean pembatalan transaksi', () => {
    beforeEach(() => {
        const store = {};
        globalThis.localStorage = { getItem: (k) => store[k] ?? null, setItem: (k, v) => { store[k] = v; }, removeItem: (k) => { delete store[k]; } };
    });

    afterEach(() => {
        delete globalThis.localStorage;
    });

    it('menyimpan pembatalan berurutan', () => {
        enqueueCancellation({ event: 'cancelled', total: 1 });
        enqueueCancellation({ event: 'cancelled', total: 2 });

        expect(readCancelQueue().map((b) => b.total)).toEqual([1, 2]);
    });

    it('mengirim semua dan mengosongkan antrean', async () => {
        enqueueCancellation({ total: 1 });
        enqueueCancellation({ total: 2 });
        const send = vi.fn().mockResolvedValue({});

        expect(await flushCancelQueue(send)).toBe(0);
        expect(send).toHaveBeenCalledTimes(2);
        expect(readCancelQueue()).toEqual([]);
    });

    it('berhenti saat jaringan putus dan menyisakan yang belum terkirim', async () => {
        enqueueCancellation({ total: 1 });
        enqueueCancellation({ total: 2 });
        enqueueCancellation({ total: 3 });
        const send = vi.fn().mockResolvedValueOnce({}).mockRejectedValueOnce(new TypeError('Failed to fetch'));

        expect(await flushCancelQueue(send)).toBe(2);
        expect(send).toHaveBeenCalledTimes(2);
        expect(readCancelQueue().map((b) => b.total)).toEqual([2, 3]);
    });

    it('menahan antrean saat konflik sesi (409) atau server error', async () => {
        enqueueCancellation({ total: 1 });

        expect(await flushCancelQueue(vi.fn().mockRejectedValue(httpError(409)))).toBe(1);
        expect(await flushCancelQueue(vi.fn().mockRejectedValue(httpError(500)))).toBe(1);
    });

    it('membuang pembatalan yang ditolak server (422) agar antrean tidak macet', async () => {
        enqueueCancellation({ total: 1 });
        enqueueCancellation({ total: 2 });
        const send = vi.fn().mockRejectedValueOnce(httpError(422)).mockResolvedValueOnce({});

        expect(await flushCancelQueue(send)).toBe(0);
        expect(readCancelQueue()).toEqual([]);
    });
});
