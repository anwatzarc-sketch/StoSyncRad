import { describe, expect, it } from 'vitest';
import { actionsFor, fillTemplate, followUps, isEditable, stepIndex, taskHref } from './workflow';
import type { NextLink, ResourceAction, WorkflowMeta } from '@/types/shared';

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

describe('follow-up links', () => {
    const next: NextLink[] = [
        { label: 'Issue the stock', href: '/r/stock-issues/new?prefill[stock_request_id]={id}', icon: 'package', when: { status: ['approved', 'partially_fulfilled'] } },
        { label: 'New purchase order', href: '/r/purchase-orders/new?prefill[supplier_id]={id}', icon: 'cart', when: { partner_types: ['supplier'] } },
        { label: 'Open the original movement', href: '/r/movements/{original_movement_id}', icon: 'history' },
    ];

    it('offers a hand-off only in the states it applies to, with the record filled in', () => {
        expect(followUps(next.slice(0, 1), { id: 'r1', status: 'approved' }).map((n) => n.href)).toEqual(['/r/stock-issues/new?prefill[stock_request_id]=r1']);
        expect(followUps(next.slice(0, 1), { id: 'r1', status: 'draft' })).toEqual([]);
    });

    it('matches list fields on any value and drops links whose record value is missing', () => {
        expect(followUps(next.slice(1, 2), { id: 'p1', partner_types: ['customer', 'supplier'] })).toHaveLength(1);
        expect(followUps(next.slice(1, 2), { id: 'p1', partner_types: ['customer'] })).toEqual([]);
        expect(followUps(next.slice(2), { id: 'x' })).toEqual([]);
    });

    it('encodes record values placed in a link', () => {
        expect(fillTemplate('/trace?lot={id}', { id: 'a/b c' })).toBe('/trace?lot=a%2Fb%20c');
        expect(fillTemplate('/r/movements/{stock_movement_id}', { stock_movement_id: null })).toBeNull();
    });
});
