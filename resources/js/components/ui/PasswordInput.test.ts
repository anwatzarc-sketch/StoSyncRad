import { describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import PasswordInput from './PasswordInput.vue';
import { toasts } from '@/lib/toast';

describe('PasswordInput', () => {
    it('masks by default and toggles visibility, passing attributes to the input', async () => {
        const wrapper = mount(PasswordInput, { props: { id: 'pw', modelValue: 'secret', autocomplete: 'current-password', required: true } });
        const input = wrapper.get('input');
        expect(input.attributes('type')).toBe('password');
        expect(input.attributes('autocomplete')).toBe('current-password');
        expect(input.attributes('required')).toBeDefined();
        expect(wrapper.text()).not.toContain('Generate');

        await wrapper.get('button[aria-controls="pw"]').trigger('click');
        expect(input.attributes('type')).toBe('text');
        expect(wrapper.get('button[aria-controls="pw"]').attributes('aria-label')).toBe('Hide password');
    });

    it('generates a strong password, reveals it, hands it to the repeat field and copies it', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        const wrapper = mount(PasswordInput, { props: { id: 'pw', modelValue: '', generate: true } });

        await wrapper.get('button:not([aria-controls])').trigger('click');
        await flushPromises();

        const [[password]] = wrapper.emitted('update:modelValue') as [[string]];
        expect(password).toHaveLength(20);
        expect(wrapper.emitted('generated')).toEqual([[password]]);
        expect(wrapper.get('input').attributes('type')).toBe('text');
        expect(writeText).toHaveBeenCalledWith(password);
        expect(toasts.at(-1)?.message).toContain('copied');
    });
});
