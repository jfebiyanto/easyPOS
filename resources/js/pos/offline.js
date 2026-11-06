const KEY = 'easypos.pos.queue';

export function readQueue() {
    try {
        return JSON.parse(localStorage.getItem(KEY)) || [];
    } catch {
        return [];
    }
}

export function writeQueue(queue) {
    localStorage.setItem(KEY, JSON.stringify(queue));
}

export function enqueue(sale) {
    const queue = readQueue();
    queue.push(sale);
    writeQueue(queue);
    return queue.length;
}

/**
 * Antrean ada di perangkat dan bisa berisi sisa gerai lain; daftar transaksi hanya menampilkan gerai yang sedang dipakai.
 */
export function pendingForStore(queue, storeId) {
    if (!storeId) return [];
    return queue.filter((item) => item.store_id === storeId);
}

export function removeFromQueue(clientUuid) {
    writeQueue(readQueue().filter((item) => item.client_uuid !== clientUuid));
}

export function clearQueue() {
    writeQueue([]);
}
