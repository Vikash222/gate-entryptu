import React from 'react';
import { LogIn, LogOut, Shield, UserPlus, AlertTriangle, Car, MapPin, Briefcase } from 'lucide-react';
import type { AppNotification } from '../../types';

interface NotificationItemProps {
  notification: AppNotification;
  onMarkAsRead: (id: string) => void;
}

export const NotificationItem: React.FC<NotificationItemProps> = ({ notification, onMarkAsRead }) => {
  const isUnread = notification.read_at === null;
  const data = notification.data || {};

  const getIcon = () => {
    if (data.type === 'STUDENT_MOVEMENT') {
      if (data.movement_type === 'IN') {
        return (
          <div className="p-2 bg-emerald-100 text-emerald-600 rounded-xl flex-shrink-0">
            <LogIn className="h-4 w-4" />
          </div>
        );
      }
      return (
        <div className="p-2 bg-rose-100 text-rose-600 rounded-xl flex-shrink-0">
          <LogOut className="h-4 w-4" />
        </div>
      );
    }

    if (data.type === 'SECURITY_DUTY') {
      return (
        <div className="p-2 bg-blue-100 text-blue-600 rounded-xl flex-shrink-0">
          <Shield className="h-4 w-4" />
        </div>
      );
    }

    if (data.type === 'STUDENT_REGISTRATION') {
      return (
        <div className="p-2 bg-purple-100 text-purple-600 rounded-xl flex-shrink-0">
          <UserPlus className="h-4 w-4" />
        </div>
      );
    }

    return (
      <div className="p-2 bg-amber-100 text-amber-600 rounded-xl flex-shrink-0">
        <AlertTriangle className="h-4 w-4" />
      </div>
    );
  };

  const formatTimestamp = (tsString?: string) => {
    if (!tsString) return '';
    try {
      const d = new Date(tsString);
      return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + ' · ' + d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    } catch {
      return tsString;
    }
  };

  return (
    <div
      onClick={() => {
        if (isUnread) {
          onMarkAsRead(notification.id);
        }
      }}
      className={`p-3.5 border-b border-slate-100 transition flex items-start gap-3 cursor-pointer select-none ${
        isUnread ? 'bg-blue-50/40 hover:bg-blue-50/70' : 'bg-white hover:bg-slate-50'
      }`}
    >
      {getIcon()}

      <div className="flex-1 min-w-0">
        <div className="flex items-center justify-between gap-1 mb-0.5">
          <h5 className={`text-xs font-bold truncate ${isUnread ? 'text-slate-900' : 'text-slate-700'}`}>
            {data.title || 'Notification'}
          </h5>
          <span className="text-[10px] font-mono text-slate-400 whitespace-nowrap flex-shrink-0">
            {formatTimestamp(data.server_timestamp || notification.created_at)}
          </span>
        </div>

        <p className="text-xs text-slate-600 leading-snug break-words">
          {data.message}
        </p>

        {/* Detailed context for OUT movement (Destination, Purpose, Vehicle) */}
        {data.movement_type === 'OUT' && (data.destination || data.purpose || data.vehicle_number) && (
          <div className="mt-2 pt-2 border-t border-slate-200/60 flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-slate-500">
            {data.destination && (
              <span className="inline-flex items-center gap-1">
                <MapPin className="h-3 w-3 text-rose-500 flex-shrink-0" />
                <span className="font-semibold text-slate-700">{data.destination}</span>
              </span>
            )}
            {data.purpose && (
              <span className="inline-flex items-center gap-1">
                <Briefcase className="h-3 w-3 text-slate-400 flex-shrink-0" />
                <span>{data.purpose}</span>
              </span>
            )}
            {data.vehicle_present && data.vehicle_number && (
              <span className="inline-flex items-center gap-1 font-mono font-bold text-slate-700">
                <Car className="h-3 w-3 text-blue-500 flex-shrink-0" />
                <span>{data.vehicle_number}</span>
              </span>
            )}
          </div>
        )}
      </div>

      {isUnread && (
        <span className="h-2 w-2 rounded-full bg-blue-600 flex-shrink-0 mt-1.5 ring-2 ring-blue-100" />
      )}
    </div>
  );
};
