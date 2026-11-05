import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const css = readFileSync(new URL('../../../public/css/pos.css', import.meta.url), 'utf8');

describe('kerangka layar POS (pos.css)', () => {
    it.each(['header', 'ribbons', 'main', 'bottom', 'fkeys'])('bagian %s punya area grid bernama', (area) => {
        expect(css).toContain(`.pos > .pos-${area} { grid-area: ${area}; }`);
        expect(css).toMatch(/grid-template-areas:\s*'header' 'ribbons' 'main' 'bottom' 'fkeys';/);
    });

    it('pita peringatan kosong tidak disembunyikan dengan display: none (menggeser baris grid)', () => {
        const rule = css.match(/\.pos-ribbons:empty\s*\{([^}]*)\}/);
        expect(rule).not.toBeNull();
        expect(rule[1]).not.toMatch(/display\s*:\s*none/);
    });
});
