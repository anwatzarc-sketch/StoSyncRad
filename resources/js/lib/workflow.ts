import type { ResourceAction, WorkflowMeta } from '@/types/shared';

/**
 * Record-page helpers for documents with a workflow (WorkflowCatalog): which permitted actions apply in
 * the record's current status, in workflow order with the primary next steps first. Display only —
 * the server still validates every transition.
 */
export function actionsFor(actions: ResourceAction[], workflow: WorkflowMeta | null, status: string | null | undefined): {
    primary: ResourceAction[];
    more: ResourceAction[];
} {
    if (!workflow) {
        return { primary: [], more: actions };
    }
    const order = Object.keys(workflow.actions);
    const valid = actions
        .filter((a) => status != null && (workflow.actions[a.name]?.from ?? []).includes(status))
        .sort((a, b) => order.indexOf(a.name) - order.indexOf(b.name));
    const primary = valid.filter((a) => workflow.actions[a.name].primary);

    return { primary, more: valid.filter((a) => !primary.includes(a)) };
}

/** The scan-first task screen an action opens instead of a dialog, if any. */
export function taskHref(workflow: WorkflowMeta | null, action: ResourceAction, id: string): string | null {
    const task = workflow?.actions[action.name]?.task;

    return task ? task.replace('{id}', id) : null;
}

/** Position on the happy path; -1 when the record left it (cancelled, rejected, returned…). */
export function stepIndex(workflow: WorkflowMeta, status: string | null | undefined): number {
    return status == null ? -1 : workflow.steps.indexOf(status);
}

/** Records with a workflow are edited only in their editable statuses (usually draft). */
export function isEditable(workflow: WorkflowMeta | null, status: string | null | undefined): boolean {
    return !workflow || (status != null && workflow.editable.includes(status));
}
