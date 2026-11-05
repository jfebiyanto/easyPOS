import { describe, expect, it } from 'vitest';
import { approvalMissing, normalizePayments, requiresApproval } from './payment.js';

const types = [
    { id: 'cash', code: 'CASH', requires_approval: false },
    { id: 'edc', code: 'EDC', requires_approval: true },
];

describe('kode approval pembayaran non-tunai', () => {
    it('hanya tipe yang dipetakan ke kartu kredit SAP yang memerlukannya', () => {
        expect(requiresApproval(types, 'edc')).toBe(true);
        expect(requiresApproval(types, 'cash')).toBe(false);
        expect(requiresApproval(types, 'tidak-ada')).toBe(false);
    });

    it('menandai baris Lunas tanpa kode approval', () => {
        expect(approvalMissing([{ payment_type_id: 'edc', status: 'confirmed', approval_code: '  ' }], types)).toBe(true);
        expect(approvalMissing([{ payment_type_id: 'edc', status: 'confirmed', approval_code: '123456' }], types)).toBe(false);
    });

    it('tidak mewajibkan kode approval untuk tunai atau pembayaran pending', () => {
        expect(approvalMissing([{ payment_type_id: 'cash', status: 'confirmed' }], types)).toBe(false);
        expect(approvalMissing([{ payment_type_id: 'edc', status: 'pending', approval_code: '' }], types)).toBe(false);
    });

    it('merapikan kode approval dan membuangnya untuk tipe yang tidak memerlukan', () => {
        expect(normalizePayments([
            { payment_type_id: 'edc', amount: 5, status: 'confirmed', approval_code: ' 123456 ' },
            { payment_type_id: 'cash', amount: 5, status: 'confirmed', approval_code: 'x' },
        ], types)).toEqual([
            { payment_type_id: 'edc', amount: 5, status: 'confirmed', approval_code: '123456' },
            { payment_type_id: 'cash', amount: 5, status: 'confirmed' },
        ]);
    });
});
