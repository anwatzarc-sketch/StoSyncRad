/**
 * Strong random passwords for the "Generate" helper on password fields. Web Crypto randomness with
 * rejection sampling (no modulo bias); every password holds a lowercase letter, an uppercase letter,
 * a digit and a symbol, so any identity.password.require_classes setting (1–4) is met. Look-alike
 * characters (0/O, 1/l/I) are left out so a password copied by hand still works.
 */
const LOWER = 'abcdefghijkmnopqrstuvwxyz';
const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
const DIGITS = '23456789';
const SYMBOLS = '!@#$%^&*-_=+?';
const CLASSES = [LOWER, UPPER, DIGITS, SYMBOLS];
const ALL = CLASSES.join('');

/** Well above the default identity.password.min_length (12). */
export const GENERATED_PASSWORD_LENGTH = 20;

function randomIndex(size: number): number {
    const limit = Math.floor(2 ** 32 / size) * size;
    const word = new Uint32Array(1);
    do {
        crypto.getRandomValues(word);
    } while (word[0] >= limit);

    return word[0] % size;
}

export function generatePassword(length: number = GENERATED_PASSWORD_LENGTH): string {
    const chars = CLASSES.map((set) => set[randomIndex(set.length)]);
    while (chars.length < length) {
        chars.push(ALL[randomIndex(ALL.length)]);
    }
    for (let i = chars.length - 1; i > 0; i--) {
        const j = randomIndex(i + 1);
        [chars[i], chars[j]] = [chars[j], chars[i]];
    }

    return chars.join('');
}
