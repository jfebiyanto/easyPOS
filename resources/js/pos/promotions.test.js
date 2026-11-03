import { describe, expect, it } from 'vitest';
import cases from '../../../tests/fixtures/promotion-cases.json';
import { activePromotions, applyPromotions } from './promotions.js';

describe('applyPromotions (sama dengan PromotionService PHP)', () => {
    it.each(cases)('$name', ({ promotions, lines, expected }) => {
        expect(applyPromotions(lines, promotions)).toEqual(expected);
    });
});

describe('activePromotions', () => {
    it('hanya mengambil promosi aktif dalam periode, termasuk tepat di batas waktu', () => {
        const at = new Date('2026-10-15T12:00:00Z');
        const promotions = [
            { code: 'OPEN', starts_at: null, ends_at: null },
            { code: 'NOW', starts_at: '2026-10-15T12:00:00Z', ends_at: '2026-10-15T12:00:00Z' },
            { code: 'OFF', is_active: false },
            { code: 'FUTURE', starts_at: '2026-10-15T12:00:01Z' },
            { code: 'PAST', ends_at: '2026-10-15T11:59:59Z' },
        ];

        expect(activePromotions(promotions, at).map((p) => p.code)).toEqual(['OPEN', 'NOW']);
    });
});
