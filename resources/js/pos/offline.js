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

export function removeFromQueue(clientUuid) {
    writeQueue(readQueue().filter((item) => item.client_uuid !== clientUuid));
}

export function clearQueue() {
    writeQueue([]);
}
