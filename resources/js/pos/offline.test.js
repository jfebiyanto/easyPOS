import { describe, expect, it } from 'vitest';
import { pendingForStore } from './offline.js';

describe('antrean offline per gerai', () => {
    const queue = [
        { client_uuid: 'a', store_id: 'gerai-1' },
        { client_uuid: 'b', store_id: 'gerai-2' },
        { client_uuid: 'c', store_id: 'gerai-1' },
        { client_uuid: 'd' },
    ];

    it('hanya mengembalikan transaksi tertunda milik gerai yang sedang dipakai', () => {
        expect(pendingForStore(queue, 'gerai-1').map((q) => q.client_uuid)).toEqual(['a', 'c']);
    });

    it('tidak menampilkan apa pun bila gerai tidak diketahui', () => {
        expect(pendingForStore(queue, undefined)).toEqual([]);
    });
});
