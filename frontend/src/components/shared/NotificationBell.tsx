import React from 'react';
import { Bell } from 'lucide-react';
import { useNotifications } from '../../context/NotificationContext';
import { NotificationPanel } from './NotificationPanel';

interface NotificationBellProps {
  variant?: 'light' | 'dark';
}

export const NotificationBell: React.FC<NotificationBellProps> = ({ variant = 'dark' }) => {
  const { unreadCount, isPanelOpen, togglePanel, closePanel } = useNotifications();

  const isLight = variant === 'light';

  return (
    <div className="relative inline-block">
      <button
        type="button"
        onClick={togglePanel}
        className={`relative p-2 rounded-xl transition flex items-center justify-center ${
          isLight
            ? 'text-slate-500 hover:text-slate-800 hover:bg-slate-100'
            : 'text-slate-300 hover:text-white hover:bg-slate-800'
        }`}
        title="Notifications"
        aria-label={`Notifications (${unreadCount} unread)`}
      >
        <Bell className="h-5 w-5" />

        {/* Unread Count Badge */}
        {unreadCount > 0 && (
          <span className="absolute -top-1 -right-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-600 px-1 text-[10px] font-black text-white shadow-sm ring-2 ring-white">
            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-rose-400 opacity-75" />
            <span className="relative">{unreadCount > 99 ? '99+' : unreadCount}</span>
          </span>
        )}
      </button>

      {/* Embedded dropdown panel */}
      <NotificationPanel isOpen={isPanelOpen} onClose={closePanel} />
    </div>
  );
};
