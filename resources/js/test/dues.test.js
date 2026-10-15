import { describe, expect, it } from 'vitest';
import { formatDuesMoney, formatMemberDues, toMajorUnits, toMinorUnits } from '@/lib/dues';

describe('toMinorUnits', () => {
    it('converts major units without float drift', () => {
        expect(toMinorUnits('50')).toBe(5000);
        expect(toMinorUnits('19.99')).toBe(1999);
        expect(toMinorUnits('0.10')).toBe(10);
    });

    it('treats a blank box as no value', () => {
        expect(toMinorUnits('')).toBeNull();
        expect(toMinorUnits('  ')).toBeNull();
    });

    it('refuses text, negatives and fractions of a pesewa', () => {
        expect(toMinorUnits('abc')).toBeNaN();
        expect(toMinorUnits('-5')).toBeNaN();
        expect(toMinorUnits('1.234')).toBeNaN();
    });
});

describe('money display', () => {
    it('formats minor units with the currency', () => {
        expect(formatDuesMoney(5000, 'GHS')).toBe('GHS 50.00');
    });

    it('fills an edit box with major units', () => {
        expect(toMajorUnits(5000)).toBe('50.00');
        expect(toMajorUnits(null)).toBe('');
    });
});

describe('formatMemberDues', () => {
    it('says No dues for a type without dues, not GHS 0.00', () => {
        expect(formatMemberDues(null, 'GHS')).toBe('No dues');
    });

    it('keeps a waived amount of zero as money', () => {
        expect(formatMemberDues(0, 'GHS')).toBe(formatDuesMoney(0, 'GHS'));
    });
});
