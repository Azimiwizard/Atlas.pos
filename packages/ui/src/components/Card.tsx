import type { HTMLAttributes, ReactNode } from 'react';
import { cn } from '../utils/cn';

export interface CardProps extends HTMLAttributes<HTMLDivElement> {
  heading?: ReactNode;
  description?: ReactNode;
  footer?: ReactNode;
  actions?: ReactNode;
  children?: ReactNode;
}

export function Card({ className, heading, description, actions, footer, children, ...props }: CardProps) {
  return (
    <article
      className={cn('rounded-xl border border-slate-200 bg-white shadow-sm ring-1 ring-black/5', className)}
      {...props}
    >
      {(heading || actions || description) && (
        <header className="flex flex-col gap-2 border-b border-slate-100 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="space-y-1">
            {heading ? <h2 className="text-lg font-semibold text-slate-900">{heading}</h2> : null}
            {description ? <p className="text-sm text-slate-500">{description}</p> : null}
          </div>
          {actions ? <div className="flex flex-wrap gap-2">{actions}</div> : null}
        </header>
      )}

      <div className="px-6 py-5 text-sm text-slate-700">{children}</div>

      {footer ? <footer className="border-t border-slate-100 px-6 py-4 text-sm text-slate-500">{footer}</footer> : null}
    </article>
  );
}

