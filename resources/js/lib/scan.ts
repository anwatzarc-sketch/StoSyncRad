import { get } from './api';

export interface ScannedLocation {
    id: string;
    warehouse_id: string;
    path: string;
}

/**
 * A scanned bin barcode (or typed path) resolved through GET /scan/{code}, which floor staff can use
 * without permission to browse storage locations. Null when the code is not a location.
 */
export async function scanLocation(code: string): Promise<ScannedLocation | null> {
    try {
        const hit = (await get<{ data: Record<string, any> }>(`/api/v1/scan/${encodeURIComponent(code.trim())}`)).data;

        return hit.type === 'location' ? { id: hit.storage_location_id, warehouse_id: hit.warehouse_id, path: hit.full_path } : null;
    } catch {
        return null;
    }
}
