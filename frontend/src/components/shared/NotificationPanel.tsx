import React, { useRef, useEffect } from 'react';
import { CheckCheck, Bell, BellOff, X, BellRing } from 'lucide-react';
import { useNotifications } from '../../context/NotificationContext';
import { NotificationItem } from './NotificationItem';

interface NotificationPanelProps {
  isOpen: boolean;
  onClose: () => void;
}

export const NotificationPanel: React.FC<NotificationPanelProps> = ({ isOpen, onClose }) => {
  const {
    notifications,
    unreadCount,
    isLoading,
    markAsRead,
    markAllAsRead,
    browserPermission,
    isBrowserSupported,
    requestBrowserPermission,
  } = useNotifications();

  const panelRef = useRef<HTMLDivElement>(null);

  // Close on outside click
  useEffect(() => {
    const handleOutsideClick = (e: MouseEvent) => {
      if (panelRef.current && !panelRef.current.contains(e.target as Node)) {
        onClose();
      }
    };

    if (isOpen) {
      document.addEventListener('mousedown', handleOutsideClick);
    }
    return () => document.removeEventListener('mousedown', handleOutsideClick);
  }, [isOpen, onClose]);

  // Close on Escape key
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        onClose();
      }
    };
    if (isOpen) {
      window.addEventListener('keydown', handleKeyDown);
    }
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  return (
    <div
      ref={panelRef}
      className="absolute right-0 top-full mt-2 w-80 sm:w-96 max-w-[calc(100vw-1.5rem)] bg-white rounded-2xl shadow-2xl border border-slate-200 z-50 overflow-hidden flex flex-col text-slate-900 animate-in fade-in slide-in-from-top-2 duration-150"
      style={{ maxHeight: 'min(520px, calc(100vh - 5rem))' }}
    >
      {/* Panel Header */}
      <div className="p-3.5 bg-slate-900 text-white flex items-center justify-between border-b border-slate-800 flex-shrink-0">
        <div className="flex items-center gap-2">
          <Bell className="h-4 w-4 text-blue-400" />
          <h4 className="text-xs font-bold uppercase tracking-wider">Notifications</h4>
          {unreadCount > 0 && (
            <span className="px-2 py-0.5 rounded-full bg-blue-600 text-white text-[10px] font-bold">
              {unreadCount} new
            </span>
          )}
        </div>

        <div className="flex items-center gap-1.5">
          {unreadCount > 0 && (
            <button
              type="button"
              onClick={() => markAllAsRead()}
              className="text-[11px] text-slate-300 hover:text-white flex items-center gap-1 px-2 py-1 rounded-lg hover:bg-slate-800 transition"
              title="Mark all as read"
            >
              <CheckCheck className="h-3.5 w-3.5 text-blue-400" />
              <span>Mark all read</span>
            </button>
          )}
          <button
            type="button"
            onClick={onClose}
            className="p-1 text-slate-400 hover:text-white rounded-lg hover:bg-slate-800 transition"
            title="Close"
          >
            <X className="h-4 w-4" />
          </button>
        </div>
      </div>

      {/* Browser notifications permission prompt banner (Opt-in only after explicit user interaction) */}
      {isBrowserSupported && browserPermission === 'default' && (
        <div className="p-3 bg-blue-50 border-b border-blue-100 flex items-center justify-between gap-2 flex-shrink-0">
          <div className="flex items-center gap-2 text-xs text-blue-900">
            <BellRing className="h-4 w-4 text-blue-600 flex-shrink-0" />
            <span className="text-[11px]">Get live alerts even when tab is in background</span>
          </div>
          <button
            type="button"
            onClick={() => requestBrowserPermission()}
            className="px-2.5 py-1 bg-blue-600 hover:bg-blue-700 text-white text-[10px] font-bold rounded-lg shadow-sm whitespace-nowrap transition"
          >
            Enable
          </button>
        </div>
      )}

      {/* Notification List Scroll Area */}
      <div className="overflow-y-auto flex-1 divide-y divide-slate-100">
        {isLoading && notifications.length === 0 ? (
          <div className="p-8 text-center text-slate-400 space-y-2">
            <div className="h-6 w-6 border-2 border-blue-600 border-t-transparent rounded-full animate-spin mx-auto" />
            <p className="text-xs">Loading notifications...</p>
          </div>
        ) : notifications.length === 0 ? (
          <div className="p-10 text-center text-slate-400 space-y-2">
            <div className="p-3 bg-slate-100 rounded-full text-slate-400 inline-block">
              <BellOff className="h-6 w-6" />
            </div>
            <p className="text-xs font-semibold text-slate-600">No notifications yet</p>
            <p className="text-[11px] text-slate-400">Activity updates and gate entries will appear here live.</p>
          </div>
        ) : (
          notifications.map((n) => (
            <NotificationItem key={n.id} notification={n} onMarkAsRead={markAsRead} />
          ))
        )}
      </div>
    </div>
  );
};
