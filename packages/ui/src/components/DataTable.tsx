import type { HTMLAttributes, ReactNode } from 'react';
import { cn } from '../utils/cn';

export type DataTableColumn<T extends Record<string, unknown>> = {
  id?: string;
  header: ReactNode;
  accessor?: keyof T;
  render?: (row: T) => ReactNode;
  className?: string;
};

export interface DataTableProps<T extends Record<string, unknown>> extends HTMLAttributes<HTMLDivElement> {
  data: T[];
  columns: Array<DataTableColumn<T>>;
  keyField?: keyof T;
  emptyMessage?: string;
  children?: never;
}

export function DataTable<T extends Record<string, unknown>>({
  data,
  columns,
  keyField,
  emptyMessage = 'No records found.',
  className,
  ...props
}: DataTableProps<T>) {
  const resolveCell = (row: T, column: DataTableColumn<T>) => {
    if (column.render) {
      return column.render(row);
    }

    if (column.accessor) {
      const value = row[column.accessor];
      return value == null ? '--' : (value as ReactNode);
    }

    return null;
  };

  const resolveKey = (row: T, index: number) => {
    if (keyField && Object.prototype.hasOwnProperty.call(row, keyField)) {
      const keyValue = row[keyField];
      if (typeof keyValue === 'string' || typeof keyValue === 'number') {
        return keyValue;
      }
    }

    return index;
  };

  return (
    <div className={cn('overflow-hidden rounded-lg border border-slate-200 bg-white', className)} {...props}>
      <table className="min-w-full divide-y divide-slate-200 text-left text-sm text-slate-700">
        <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
          <tr>
            {columns.map((column, index) => (
              <th key={column.id ?? index} scope="col" className={cn('px-4 py-3', column.className)}>
                {column.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {data.length === 0 ? (
            <tr>
              <td colSpan={columns.length} className="px-4 py-6 text-center text-sm text-slate-400">
                {emptyMessage}
              </td>
            </tr>
          ) : (
            data.map((row, rowIndex) => (
              <tr key={resolveKey(row, rowIndex)} className="transition-colors hover:bg-slate-50">
                {columns.map((column, columnIndex) => (
                  <td key={column.id ?? columnIndex} className={cn('px-4 py-3 align-middle', column.className)}>
                    {resolveCell(row, column)}
                  </td>
                ))}
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}

