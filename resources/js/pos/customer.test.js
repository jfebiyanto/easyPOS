import { describe, expect, it } from 'vitest';
import { customerPanel } from './customer.js';

const selected = { id: 'a', code: 'C-001', name: 'Budi' };
const retail = { id: 'b', code: 'C-RTL', name: 'Pelanggan Retail' };

describe('customerPanel', () => {
    it('mengutamakan pelanggan pilihan kasir di atas default gerai', () => {
        expect(customerPanel(selected, retail)).toEqual({ code: 'C-001', name: 'Budi', isSelected: true, isDefault: false });
    });

    it('menampilkan pelanggan default gerai bila kasir belum memilih', () => {
        expect(customerPanel(null, retail)).toEqual({ code: 'C-RTL', name: 'Pelanggan Retail', isSelected: false, isDefault: true });
    });

    it('menulis tanpa pelanggan bila gerai tidak punya default', () => {
        expect(customerPanel(null, null)).toEqual({ code: null, name: 'No customer', isSelected: false, isDefault: false });
    });

    it('menandai pelanggan yang belum punya kode', () => {
        expect(customerPanel({ id: 'c', code: null, name: 'Baru' }, retail).code).toBe('No code yet');
    });
});
