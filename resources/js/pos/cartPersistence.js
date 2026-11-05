// Keranjang yang sedang berjalan disimpan ke server (utama) dan ke localStorage (cadangan sinkron untuk
// perubahan terakhir sebelum sempat terkirim, atau saat offline). Saat POS dibuka, yang terbaru dipulihkan.

const KEY = 'easypos.pos.cart';

export function buildCartPayload({ cart, delivery, customer, activeHold }, now = new Date()) {
    return {
        cart,
        delivery,
        customer: customer ?? null,
        active_hold: activeHold ?? null,
        saved_at: now.toISOString(),
    };
}

export const isEmptyCart = (payload) => !payload || !Array.isArray(payload.cart) || payload.cart.length === 0;

export function writeCartBackup(userId, payload) {
    try {
        localStorage.setItem(KEY, JSON.stringify({ user_id: userId, payload }));
    } catch {
        /* cadangan hanya pelengkap simpanan server */
    }
}

export function readCartBackup(userId) {
    try {
        const stored = JSON.parse(localStorage.getItem(KEY));

        return stored && stored.user_id === userId ? stored.payload : null;
    } catch {
        return null;
    }
}

export function clearCartBackup() {
    try {
        localStorage.removeItem(KEY);
    } catch {
        /* abaikan */
    }
}

/**
 * Pilih keranjang yang dipulihkan: yang terbaru di antara simpanan server dan cadangan browser.
 * Keranjang kosong diabaikan; null bila keduanya kosong.
 */
export function pickNewestCart(server, local) {
    const candidates = [server, local].filter((payload) => !isEmptyCart(payload));
    if (candidates.length === 0) return null;

    const time = (payload) => Date.parse(payload.saved_at) || 0;

    return candidates.reduce((best, payload) => (time(payload) > time(best) ? payload : best));
}
