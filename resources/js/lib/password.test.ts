import { describe, expect, it } from 'vitest';
import { GENERATED_PASSWORD_LENGTH, generatePassword } from './password';

describe('generatePassword', () => {
    it('meets every character class of the password policy', () => {
        for (let i = 0; i < 200; i++) {
            const password = generatePassword();
            expect(password).toHaveLength(GENERATED_PASSWORD_LENGTH);
            expect(password).toMatch(/[a-z]/);
            expect(password).toMatch(/[A-Z]/);
            expect(password).toMatch(/\d/);
            expect(password).toMatch(/[^A-Za-z0-9]/);
        }
    });

    it('leaves out look-alike characters and whitespace', () => {
        const sample = Array.from({ length: 200 }, () => generatePassword()).join('');
        expect(sample).not.toMatch(/[0O1lI\s]/);
    });

    it('honours a longer length and never repeats', () => {
        expect(generatePassword(64)).toHaveLength(64);
        expect(new Set(Array.from({ length: 100 }, () => generatePassword())).size).toBe(100);
    });
});
