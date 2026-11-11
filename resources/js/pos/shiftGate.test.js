import { describe, expect, it } from 'vitest';
import { countFloat, floatVariance, gateScreen } from './shiftGate.js';

describe('gateScreen', () => {
    it('menampilkan Open Store bila toko belum dibuka', () => {
        expect(gateScreen({ state: 'not_opened', has_shift: false })).toBe('open_store');
    });

    it('menampilkan Start Shift bila toko open dan kasir belum punya shift', () => {
        expect(gateScreen({ state: 'open', has_shift: false })).toBe('start_shift');
    });

    it('tidak menampilkan gerbang bila toko open dan shift aktif', () => {
        expect(gateScreen({ state: 'open', has_shift: true })).toBeNull();
    });

    it('memblokir bila EOD sudah ditutup', () => {
        expect(gateScreen({ state: 'eod_done', has_shift: false })).toBe('blocked');
        expect(gateScreen({ state: 'eod_done', has_shift: true })).toBe('blocked');
    });

    it('tidak menampilkan gerbang sebelum data tersedia', () => {
        expect(gateScreen(null)).toBeNull();
        expect(gateScreen(undefined)).toBeNull();
    });
});

describe('countFloat', () => {
    it('menjumlahkan denominasi dikali jumlah lembar', () => {
        expect(countFloat({ 100000: 2, 50000: 1 })).toBe(250000);
    });

    it('mengabaikan isian kosong, negatif, atau bukan angka', () => {
        expect(countFloat({ 100000: '', 50000: 'x', 20000: -3, 10000: '2' })).toBe(20000);
    });

    it('menghasilkan 0 untuk hitungan kosong', () => {
        expect(countFloat({})).toBe(0);
    });
});

describe('floatVariance', () => {
    it('selisih hitungan terhadap modal standar', () => {
        expect(floatVariance(150000, 200000)).toBe(-50000);
        expect(floatVariance(200000, 200000)).toBe(0);
        expect(floatVariance(250000, 200000)).toBe(50000);
    });
});
