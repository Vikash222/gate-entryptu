import React from 'react';
import { AlertCircle, CheckCircle2, Info, AlertTriangle } from 'lucide-react';
import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export interface AlertProps {
  type?: 'info' | 'success' | 'warning' | 'error';
  title?: string;
  message: React.ReactNode;
  className?: string;
}

export const Alert: React.FC<AlertProps> = ({
  type = 'info',
  title,
  message,
  className,
}) => {
  const configs = {
    info: {
      border: 'border-blue-200 bg-blue-50 text-blue-900',
      icon: <Info className="h-5 w-5 text-blue-600 flex-shrink-0" />,
    },
    success: {
      border: 'border-emerald-200 bg-emerald-50 text-emerald-900',
      icon: <CheckCircle2 className="h-5 w-5 text-emerald-600 flex-shrink-0" />,
    },
    warning: {
      border: 'border-amber-200 bg-amber-50 text-amber-900',
      icon: <AlertTriangle className="h-5 w-5 text-amber-600 flex-shrink-0" />,
    },
    error: {
      border: 'border-rose-200 bg-rose-50 text-rose-900',
      icon: <AlertCircle className="h-5 w-5 text-rose-600 flex-shrink-0" />,
    },
  };

  const config = configs[type];

  return (
    <div
      className={twMerge(
        clsx(
          'flex gap-3 p-4 rounded-2xl border text-sm text-left',
          config.border,
          className
        )
      )}
    >
      {config.icon}
      <div className="flex-1">
        {title && <h4 className="font-semibold text-sm mb-0.5">{title}</h4>}
        <div className="text-xs leading-relaxed opacity-90">{message}</div>
      </div>
    </div>
  );
};
