import { describe, expect, it } from 'vitest';
import { clampPct, delta, formatCompact, formatRupiah, initials } from './format.js';

describe('formatRupiah', () => {
    it('memakai pemisah ribuan titik tanpa desimal', () => {
        expect(formatRupiah(1250000)).toBe('Rp 1.250.000');
        expect(formatRupiah(null)).toBe('Rp 0');
    });
});

describe('formatCompact', () => {
    it('meringkas nilai untuk sumbu grafik', () => {
        expect(formatCompact(1250000)).toBe('1,3 jt');
        expect(formatCompact(12500)).toBe('12,5 rb');
        expect(formatCompact(950)).toBe('950');
    });
});

describe('delta', () => {
    it('naik berwarna hijau untuk pemasukan dan merah untuk pengeluaran', () => {
        expect(delta(14)).toMatchObject({ direction: 'up', tone: 'up', text: '+14% vs kemarin', sentence: 'Naik sebesar 14%' });
        expect(delta(14, { higherIsBetter: false })).toMatchObject({ direction: 'up', tone: 'down' });
        expect(delta(-2.5)).toMatchObject({ direction: 'down', tone: 'down', text: '−2,5% vs kemarin', sentence: 'Turun sebesar 2,5%' });
    });

    it('menandai bila belum ada data pembanding', () => {
        expect(delta(null)).toMatchObject({ direction: 'flat', tone: 'muted', text: 'Belum ada data kemarin' });
    });
});

describe('helpers', () => {
    it('membatasi persentase ring 0–100 dan membuat inisial avatar', () => {
        expect(clampPct(130)).toBe(100);
        expect(clampPct(-5)).toBe(0);
        expect(initials('Sari Dewi Lestari')).toBe('SD');
    });
});
