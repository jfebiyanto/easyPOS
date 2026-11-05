// Pembatalan transaksi (Batal Transaksi / F9) yang terjadi saat offline atau gagal terkirim ditahan di browser dan
// dikirim ulang begitu tersambung, lengkap dengan waktu kejadiannya, agar pembatalan tidak lolos tanpa jejak.

const KEY = 'easypos.pos.cancel-queue';

export function readCancelQueue() {
    try {
        const queue = JSON.parse(localStorage.getItem(KEY));

        return Array.isArray(queue) ? queue : [];
    } catch {
        return [];
    }
}

function writeCancelQueue(queue) {
    try {
        if (queue.length === 0) localStorage.removeItem(KEY);
        else localStorage.setItem(KEY, JSON.stringify(queue));
    } catch {
        /* penyimpanan browser tidak tersedia */
    }
}

export function enqueueCancellation(body) {
    writeCancelQueue([...readCancelQueue(), body]);
}

/**
 * Kirim antrean berurutan. Kegagalan jaringan (tanpa status HTTP) atau konflik sesi (409/401/419) menghentikan
 * pengiriman dan menyisakan sisanya; penolakan lain (mis. 422) membuang item itu agar antrean tidak macet.
 *
 * @param {(body: object) => Promise<unknown>} send
 */
export async function flushCancelQueue(send) {
    const queue = readCancelQueue();
    const remaining = [];
    let stopped = false;

    for (const body of queue) {
        if (stopped) {
            remaining.push(body);
            continue;
        }

        try {
            await send(body);
        } catch (error) {
            const retryable = !error.status || [401, 409, 419, 429].includes(error.status) || error.status >= 500;
            if (retryable) {
                stopped = true;
                remaining.push(body);
            }
        }
    }

    writeCancelQueue(remaining);

    return remaining.length;
}
