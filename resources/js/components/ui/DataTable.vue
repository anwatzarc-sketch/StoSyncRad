<script setup lang="ts" generic="Row extends Record<string, any>">
import { Link } from '@inertiajs/vue3';
import type { Column } from './table';

/**
 * Responsive table (Chapter 12 §12.10): a real table from `md` up, stacked cards on phones.
 * Cells format through `column.format`; rows can link to a detail page.
 */
const props = withDefaults(defineProps<{
    columns: Column<Row>[];
    rows: Row[];
    rowHref?: (row: Row) => string | null;
    loading?: boolean;
    empty?: string;
    caption?: string;
}>(), { rowHref: undefined, loading: false, empty: 'Nothing to show yet.', caption: undefined });

const cell = (row: Row, c: Column<Row>) => {
    const value = c.key.split('.').reduce<any>((v, k) => (v == null ? v : v[k]), row);

    return c.format ? c.format(value, row) : value == null || value === '' ? '—' : String(value);
};
const href = (row: Row) => props.rowHref?.(row) ?? null;
</script>

<template>
    <div>
        <div v-if="loading && rows.length === 0" class="space-y-2 py-6" aria-busy="true">
            <div v-for="n in 4" :key="n" class="h-10 animate-pulse rounded-brand bg-surface-muted" />
        </div>
        <p v-else-if="rows.length === 0" class="py-8 text-center text-sm text-ink-muted">{{ empty }}</p>
        <template v-else>
            <div class="hidden overflow-x-auto md:block">
                <table class="table">
                    <caption v-if="caption" class="sr-only">{{ caption }}</caption>
                    <thead>
                        <tr>
                            <th v-for="c in columns" :key="c.key" scope="col" :class="c.align === 'right' ? 'text-right' : ''">{{ c.label }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in rows" :key="row.id ?? i" class="hover:bg-surface-muted">
                            <td v-for="(c, ci) in columns" :key="c.key" :class="c.align === 'right' ? 'text-right tabular-nums' : ''">
                                <Link v-if="ci === 0 && href(row)" :href="href(row)!" class="font-medium text-brand underline-offset-2 hover:underline">{{ cell(row, c) }}</Link>
                                <slot v-else :name="`cell-${c.key}`" :row="row" :value="cell(row, c)">{{ cell(row, c) }}</slot>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ul class="space-y-2 md:hidden">
                <li v-for="(row, i) in rows" :key="row.id ?? i">
                    <component :is="href(row) ? Link : 'div'" :href="href(row) ?? undefined" class="card block">
                        <p class="font-semibold text-ink">{{ cell(row, columns[0]) }}</p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                            <template v-for="c in columns.slice(1)" :key="c.key">
                                <dt class="text-ink-muted">{{ c.label }}</dt>
                                <dd :class="c.align === 'right' ? 'text-right tabular-nums' : ''">
                                    <slot :name="`cell-${c.key}`" :row="row" :value="cell(row, c)">{{ cell(row, c) }}</slot>
                                </dd>
                            </template>
                        </dl>
                    </component>
                </li>
            </ul>
        </template>
    </div>
</template>
