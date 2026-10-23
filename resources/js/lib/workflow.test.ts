import { describe, expect, it } from 'vitest';
import { actionsFor, isEditable, stepIndex, taskHref } from './workflow';
import type { ResourceAction, WorkflowMeta } from '@/types/shared';

const action = (name: string): ResourceAction => ({
    name, label: name, method: 'POST', uri: `/api/v1/transfers/{id}/${name}`, parameter: 'id', permission: `transfers.transfer.${name}`,
    schema: null, no_body: false, idempotent: false,
});

const transfer: WorkflowMeta = {
    status_enum: 'transfer_status',
    steps: ['draft', 'submitted', 'approved', 'in_transit', 'received', 'closed'],
    editable: ['draft'],
    actions: {
        submit: { from: ['draft'], primary: true, task: null },
        approve: { from: ['submitted'], primary: true, task: null },
        reject: { from: ['submitted'], primary: false, task: null },
        dispatch: { from: ['approved'], primary: true, task: '/tasks/transfers/{id}/dispatch' },
        cancel: { from: ['draft', 'submitted', 'approved'], primary: false, task: null },
    },
};

describe('workflow-aware record actions', () => {
    it('offers only the actions valid in the current status, primary next steps first, in workflow order', () => {
        const { primary, more } = actionsFor([action('cancel'), action('reject'), action('approve'), action('submit')], transfer, 'submitted');

        expect(primary.map((a) => a.name)).toEqual(['approve']);
        expect(more.map((a) => a.name)).toEqual(['reject', 'cancel']);
    });

    it('offers nothing the person is not permitted to do, and nothing once the document left its path', () => {
        expect(actionsFor([action('cancel')], transfer, 'submitted').primary).toEqual([]);
        expect(actionsFor([action('cancel'), action('submit')], transfer, 'cancelled')).toEqual({ primary: [], more: [] });
    });

    it('keeps every permitted action for resources without a workflow', () => {
        expect(actionsFor([action('archive')], null, 'active').more.map((a) => a.name)).toEqual(['archive']);
    });

    it('opens task screens for floor work and tracks the place on the happy path', () => {
        expect(taskHref(transfer, action('dispatch'), 'abc')).toBe('/tasks/transfers/abc/dispatch');
        expect(taskHref(transfer, action('approve'), 'abc')).toBeNull();
        expect(stepIndex(transfer, 'approved')).toBe(2);
        expect(stepIndex(transfer, 'cancelled')).toBe(-1);
        expect([isEditable(transfer, 'draft'), isEditable(transfer, 'submitted'), isEditable(null, 'active')]).toEqual([true, false, true]);
    });
});
