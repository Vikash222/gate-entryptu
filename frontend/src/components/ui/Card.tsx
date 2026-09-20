import React from 'react';
import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export interface CardProps extends React.HTMLAttributes<HTMLDivElement> {
  variant?: 'default' | 'elevated' | 'outline' | 'flat';
}

export const Card = React.forwardRef<HTMLDivElement, CardProps>(
  ({ className, variant = 'default', children, ...props }, ref) => {
    const variants = {
      default: 'bg-white border border-slate-200/80 shadow-sm rounded-2xl p-5',
      elevated: 'bg-white border border-slate-100 shadow-md shadow-slate-200/50 rounded-2xl p-6',
      outline: 'bg-transparent border border-slate-200 rounded-2xl p-5',
      flat: 'bg-slate-100 rounded-2xl p-5',
    };

    return (
      <div ref={ref} className={twMerge(clsx(variants[variant], className))} {...props}>
        {children}
      </div>
    );
  }
);

Card.displayName = 'Card';
