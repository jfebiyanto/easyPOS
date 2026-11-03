import { describe, expect, it } from 'vitest';
import { formatBusinessClock, serverOffset } from './businessClock.js';

describe('formatBusinessClock', () => {
    it('memakai zona waktu perusahaan sehingga tanggal bisnis berganti tepat tengah malam WIB', () => {
        const beforeMidnight = formatBusinessClock(new Date('2026-10-28T16:59:59Z'), 'Asia/Jakarta');
        const afterMidnight = formatBusinessClock(new Date('2026-10-28T17:00:00Z'), 'Asia/Jakarta');

        expect(beforeMidnight.isoDate).toBe('2026-10-28');
        expect(beforeMidnight.time).toContain('23:59:59');
        expect(afterMidnight.isoDate).toBe('2026-10-29');
        expect(afterMidnight.date).toContain('29 Oktober 2026');
        expect(afterMidnight.time).toContain('00:00:00');
    });
});

describe('serverOffset', () => {
    it('menghitung selisih jam server terhadap jam perangkat', () => {
        expect(serverOffset('2026-10-28T10:00:05Z', Date.parse('2026-10-28T10:00:00Z'))).toBe(5000);
        expect(serverOffset(null)).toBe(0);
    });
});
