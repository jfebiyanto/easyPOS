import { describe, expect, it, vi } from 'vitest';
import { acquireTabLock, TAB_LOCK_NAME } from './tabLock.js';

/** LockManager palsu: satu kunci per nama, dengan dukungan ifAvailable dan steal seperti Web Locks API. */
function fakeLocks() {
    const held = new Map();

    return {
        request(name, options, callback) {
            const current = held.get(name);

            if (options.ifAvailable && current) {
                return Promise.resolve(callback(null));
            }

            if (current && options.steal) {
                current.abort();
            }

            const entry = {};
            const done = new Promise((resolve, reject) => {
                entry.abort = () => reject(new DOMException('stolen', 'AbortError'));
                Promise.resolve(callback({ name })).then(resolve, reject);
            });
            held.set(name, entry);
            done.catch(() => {}).finally(() => {
                if (held.get(name) === entry) held.delete(name);
            });

            return done;
        },
    };
}

const handlers = () => ({ onAcquired: vi.fn(), onBlocked: vi.fn(), onLost: vi.fn() });
const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('kunci satu tab POS', () => {
    it('tab pertama mendapat kunci dan tab kedua diblokir', async () => {
        const locks = fakeLocks();
        const first = handlers();
        const second = handlers();

        acquireTabLock(locks, first);
        await tick();
        acquireTabLock(locks, second);
        await tick();

        expect(first.onAcquired).toHaveBeenCalledOnce();
        expect(second.onBlocked).toHaveBeenCalledOnce();
        expect(second.onAcquired).not.toHaveBeenCalled();
    });

    it('kunci yang dilepas dapat diambil tab lain', async () => {
        const locks = fakeLocks();
        const release = acquireTabLock(locks, handlers());
        await tick();

        release();
        await tick();

        const next = handlers();
        acquireTabLock(locks, next);
        await tick();
        expect(next.onAcquired).toHaveBeenCalledOnce();
    });

    it('mengambil alih kunci membuat tab lama kehilangan kunci', async () => {
        const locks = fakeLocks();
        const old = handlers();
        const taker = handlers();

        acquireTabLock(locks, old);
        await tick();
        acquireTabLock(locks, { ...taker, steal: true });
        await tick();

        expect(old.onLost).toHaveBeenCalledOnce();
        expect(taker.onAcquired).toHaveBeenCalledOnce();
    });

    it('browser tanpa Web Locks tidak dibatasi', () => {
        const only = handlers();

        acquireTabLock(null, only);

        expect(only.onAcquired).toHaveBeenCalledOnce();
        expect(only.onBlocked).not.toHaveBeenCalled();
    });

    it('memakai nama kunci yang tetap', () => {
        expect(TAB_LOCK_NAME).toBe('easypos-pos-tab');
    });
});
