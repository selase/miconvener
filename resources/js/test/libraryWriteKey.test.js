import { expect, it } from 'vitest';
import { libraryWriteKey } from '@/lib/libraryWriteKey';
it('reuses the same save key only for unchanged inputs and the same file', () => {
    const attempt = { current: null };
    const file = new File(['bytes'], 'constitution.pdf');
    const one = libraryWriteKey(attempt, { title: 'Constitution' }, file);
    expect(libraryWriteKey(attempt, { title: 'Constitution' }, file)).toBe(one);
    expect(libraryWriteKey(attempt, { title: 'Revised' }, file)).not.toBe(one);
    const next = attempt.current.key;
    expect(
        libraryWriteKey(attempt, { title: 'Revised' }, new File(['other'], 'constitution.pdf'))
    ).not.toBe(next);
});
