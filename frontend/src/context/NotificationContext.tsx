import React, { createContext, useContext, useState, useEffect, useCallback, useRef } from 'react';
import { useAuth } from './AuthContext';
import { apiClient } from '../api/client';
import { useBrowserNotifications } from '../hooks/useBrowserNotifications';
import type { ApiResponse, AppNotification } from '../types';

interface UnreadCountResponse {
  unread_count: number;
  server_time: string;
}

interface NotificationPaginationResponse {
  data: AppNotification[];
  current_page: number;
  last_page: number;
  total: number;
}

interface NotificationContextType {
  unreadCount: number;
  notifications: AppNotification[];
  isLoading: boolean;
  isPanelOpen: boolean;
  browserPermission: NotificationPermission;
  isBrowserSupported: boolean;
  requestBrowserPermission: () => Promise<NotificationPermission>;
  fetchNotifications: () => Promise<void>;
  markAsRead: (id: string) => Promise<void>;
  markAllAsRead: () => Promise<void>;
  togglePanel: () => void;
  closePanel: () => void;
}

const NotificationContext = createContext<NotificationContextType | undefined>(undefined);

export const NotificationProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { user, token } = useAuth();
  const [unreadCount, setUnreadCount] = useState<number>(0);
  const [notifications, setNotifications] = useState<AppNotification[]>([]);
  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [isPanelOpen, setIsPanelOpen] = useState<boolean>(false);

  const prevCountRef = useRef<number>(0);
  const isFetchingRef = useRef<boolean>(false);

  const { isSupported: isBrowserSupported, permission: browserPermission, requestPermission, sendNotification } = useBrowserNotifications();

  // Fetch unread count (lightweight polling)
  const fetchUnreadCount = useCallback(async () => {
    if (!token || !user) return;
    try {
      const res = await apiClient.get<ApiResponse<UnreadCountResponse>>('/notifications/unread-count');
      const newCount = res.data?.data?.unread_count ?? 0;

      // If new unread notifications arrived while user is active, trigger haptics and browser notification
      if (newCount > prevCountRef.current && prevCountRef.current !== 0) {
        if ('vibrate' in navigator) {
          navigator.vibrate([80, 40, 80]);
        }
        sendNotification('SmartGate Alert', {
          body: `You have new live gate notifications.`,
        });
      }

      prevCountRef.current = newCount;
      setUnreadCount(newCount);
    } catch {
      // Ignore background poll errors silently
    }
  }, [token, user]);

  // Fetch full notification list
  const fetchNotifications = useCallback(async () => {
    if (!token || !user || isFetchingRef.current) return;
    isFetchingRef.current = true;
    setIsLoading(true);

    try {
      const res = await apiClient.get<ApiResponse<NotificationPaginationResponse>>('/notifications?per_page=30');
      const list = res.data?.data?.data ?? [];
      setNotifications(list);

      // Also update unread count based on current list
      const unread = list.filter((n) => n.read_at === null).length;
      setUnreadCount(unread);
      prevCountRef.current = unread;
    } catch (err) {
      console.warn('Failed to fetch notifications:', err);
    } finally {
      setIsLoading(false);
      isFetchingRef.current = false;
    }
  }, [token, user]);

  // Mark single notification as read
  const markAsRead = useCallback(async (id: string) => {
    try {
      // Optimistic update
      setNotifications((prev) =>
        prev.map((n) => (n.id === id ? { ...n, read_at: new Date().toISOString() } : n))
      );
      setUnreadCount((prev) => Math.max(0, prev - 1));

      await apiClient.post(`/notifications/${id}/read`);
    } catch (err) {
      console.warn('Failed to mark notification as read:', err);
      // Re-fetch to sync truth
      fetchUnreadCount();
    }
  }, [fetchUnreadCount]);

  // Mark all notifications as read
  const markAllAsRead = useCallback(async () => {
    try {
      // Optimistic update
      setNotifications((prev) =>
        prev.map((n) => ({ ...n, read_at: n.read_at || new Date().toISOString() }))
      );
      setUnreadCount(0);
      prevCountRef.current = 0;

      await apiClient.post('/notifications/read-all');
    } catch (err) {
      console.warn('Failed to mark all notifications as read:', err);
      fetchUnreadCount();
    }
  }, [fetchUnreadCount]);

  const togglePanel = useCallback(() => {
    setIsPanelOpen((prev) => {
      const next = !prev;
      if (next) {
        fetchNotifications();
      }
      return next;
    });
  }, [fetchNotifications]);

  const closePanel = useCallback(() => {
    setIsPanelOpen(false);
  }, []);

  // Set up 5-second polling for unread count
  useEffect(() => {
    if (!token || !user) {
      setUnreadCount(0);
      setNotifications([]);
      prevCountRef.current = 0;
      return;
    }

    // Initial fetch
    fetchUnreadCount();

    const interval = setInterval(() => {
      // Only poll when page is visible to conserve battery & network
      if (typeof document !== 'undefined' && document.hidden) {
        return;
      }
      fetchUnreadCount();
    }, 5000);

    const handleVisibilityChange = () => {
      if (typeof document !== 'undefined' && !document.hidden) {
        fetchUnreadCount();
      }
    };

    document.addEventListener('visibilitychange', handleVisibilityChange);

    return () => {
      clearInterval(interval);
      document.removeEventListener('visibilitychange', handleVisibilityChange);
    };
  }, [token, user, fetchUnreadCount]);

  return (
    <NotificationContext.Provider
      value={{
        unreadCount,
        notifications,
        isLoading,
        isPanelOpen,
        browserPermission,
        isBrowserSupported,
        requestBrowserPermission: requestPermission,
        fetchNotifications,
        markAsRead,
        markAllAsRead,
        togglePanel,
        closePanel,
      }}
    >
      {children}
    </NotificationContext.Provider>
  );
};

export const useNotifications = (): NotificationContextType => {
  const context = useContext(NotificationContext);
  if (!context) {
    throw new Error('useNotifications must be used within a NotificationProvider');
  }
  return context;
};
