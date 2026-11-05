// POS hanya boleh terbuka di satu tab per browser (mencegah transaksi ganda di tab berbeda). Memakai Web Locks API:
// tab pertama memegang kunci selama hidup, dan kunci lepas otomatis saat tab ditutup atau crash.

export const TAB_LOCK_NAME = 'easypos-pos-tab';

/**
 * Ambil kunci tab POS.
 *
 * @param {?LockManager} locks navigator.locks; null bila browser tidak mendukung (pembatasan dilewati)
 * @param {{steal?: boolean, onAcquired: () => void, onBlocked: () => void, onLost: () => void}} handlers
 *   onBlocked: kunci dipegang tab lain; onLost: kunci diambil alih tab lain setelah sempat dipegang.
 * @returns {() => void} fungsi untuk melepas kunci
 */
export function acquireTabLock(locks, { steal = false, onAcquired, onBlocked, onLost }) {
    if (!locks) {
        onAcquired();
        return () => {};
    }

    let release = () => {};
    const held = new Promise((resolve) => {
        release = resolve;
    });

    locks
        .request(TAB_LOCK_NAME, steal ? { steal: true } : { ifAvailable: true }, async (lock) => {
            if (!lock) {
                onBlocked();
                return;
            }

            onAcquired();
            await held;
        })
        .catch(() => onLost());

    return () => release();
}
