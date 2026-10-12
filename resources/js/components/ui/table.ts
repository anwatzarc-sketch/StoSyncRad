/** Column of a DataTable: `key` may be a dotted path; `format` renders the cell text. */
export interface Column<R = Record<string, any>> {
    key: string;
    label: string;
    format?: (value: any, row: R) => string;
    align?: 'left' | 'right';
}
