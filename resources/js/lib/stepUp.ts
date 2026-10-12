import { reactive } from 'vue';

/**
 * Step-up re-verification (Chapter 04 §4.2): when the server answers STEP_UP_REQUIRED the API
 * client asks the person to re-verify (StepUpDialog, mounted once in the layouts) and then retries
 * the request with the same Idempotency-Key.
 */
export const stepUpState = reactive<{ open: boolean; resolve: ((ok: boolean) => void) | null }>({ open: false, resolve: null });

export function requestStepUp(): Promise<boolean> {
    if (stepUpState.resolve) {
        stepUpState.resolve(false);
    }

    return new Promise<boolean>((resolve) => {
        stepUpState.resolve = resolve;
        stepUpState.open = true;
    });
}

export function finishStepUp(ok: boolean): void {
    const resolve = stepUpState.resolve;
    stepUpState.open = false;
    stepUpState.resolve = null;
    resolve?.(ok);
}
