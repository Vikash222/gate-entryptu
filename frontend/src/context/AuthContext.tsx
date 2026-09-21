import React, { createContext, useContext, useEffect, useState, useCallback } from 'react';
import { apiClient } from '../api/client';
import type { User, ApiResponse } from '../types';

interface AuthContextType {
  user: User | null;
  token: string | null;
  sessionExpiresAt: string | null;
  isLoading: boolean;
  isAdmin: boolean;
  isSecurity: boolean;
  isStudent: boolean;
  login: (token: string, user: User, sessionExpiresAt?: string | null) => void;
  logout: () => Promise<void>;
  refreshUser: () => Promise<void>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [user, setUser] = useState<User | null>(() => {
    const cached = localStorage.getItem('smartgate_user');
    return cached ? JSON.parse(cached) : null;
  });
  const [token, setToken] = useState<string | null>(() => {
    return localStorage.getItem('smartgate_token');
  });
  const [sessionExpiresAt, setSessionExpiresAt] = useState<string | null>(() => {
    return localStorage.getItem('smartgate_session_expires_at');
  });
  const [isLoading, setIsLoading] = useState<boolean>(true);

  const isAdmin = user?.role === 'ADMIN';
  const isSecurity = user?.role === 'SECURITY';
  const isStudent = user?.role === 'STUDENT';

  const refreshUser = useCallback(async () => {
    if (!token) {
      setUser(null);
      setIsLoading(false);
      return;
    }

    try {
      const response = await apiClient.get<ApiResponse<{ user: User; session_expires_at?: string }>>('/auth/me');
      const freshUser = response.data.data.user;
      setUser(freshUser);
      localStorage.setItem('smartgate_user', JSON.stringify(freshUser));
      if (response.data.data.session_expires_at) {
        setSessionExpiresAt(response.data.data.session_expires_at);
        localStorage.setItem('smartgate_session_expires_at', response.data.data.session_expires_at);
      }
    } catch {
      setUser(null);
      setToken(null);
      setSessionExpiresAt(null);
      localStorage.removeItem('smartgate_token');
      localStorage.removeItem('smartgate_user');
      localStorage.removeItem('smartgate_session_expires_at');
    } finally {
      setIsLoading(false);
    }
  }, [token]);

  useEffect(() => {
    refreshUser();
  }, [refreshUser]);

  // Multi-tab sync: detect when another tab logged out or session expired
  useEffect(() => {
    const handleStorageChange = (e: StorageEvent) => {
      if (e.key === 'smartgate_token' && !e.newValue) {
        setToken(null);
        setUser(null);
        setSessionExpiresAt(null);
        window.location.href = '/login';
      }
    };
    window.addEventListener('storage', handleStorageChange);
    return () => window.removeEventListener('storage', handleStorageChange);
  }, []);

  // Absolute 8-hour session expiry check for Student & Security Guard
  useEffect(() => {
    if (!token || !sessionExpiresAt || (!isStudent && !isSecurity)) {
      return;
    }

    const checkExpiry = () => {
      const expiryTime = new Date(sessionExpiresAt).getTime();
      const now = Date.now();

      if (now >= expiryTime) {
        setToken(null);
        setUser(null);
        setSessionExpiresAt(null);
        localStorage.removeItem('smartgate_token');
        localStorage.removeItem('smartgate_user');
        localStorage.removeItem('smartgate_session_expires_at');
        sessionStorage.setItem('smartgate_session_expired_message', 'Your 8-hour session has expired. Please sign in again.');
        window.location.href = '/login';
      }
    };

    checkExpiry();
    const interval = setInterval(checkExpiry, 5000);
    return () => clearInterval(interval);
  }, [token, sessionExpiresAt, isStudent, isSecurity]);

  const login = (newToken: string, newUser: User, expiresAt?: string | null) => {
    setToken(newToken);
    setUser(newUser);
    localStorage.setItem('smartgate_token', newToken);
    localStorage.setItem('smartgate_user', JSON.stringify(newUser));

    if (newUser.role === 'STUDENT' || newUser.role === 'SECURITY') {
      const expiry = expiresAt || new Date(Date.now() + 8 * 3600 * 1000).toISOString();
      setSessionExpiresAt(expiry);
      localStorage.setItem('smartgate_session_expires_at', expiry);
    } else {
      setSessionExpiresAt(null);
      localStorage.removeItem('smartgate_session_expires_at');
    }
  };

  const logout = async () => {
    try {
      if (token) {
        await apiClient.post('/auth/logout');
      }
    } catch (err) {
      console.error('Logout error:', err);
    } finally {
      setToken(null);
      setUser(null);
      setSessionExpiresAt(null);
      localStorage.removeItem('smartgate_token');
      localStorage.removeItem('smartgate_user');
      localStorage.removeItem('smartgate_session_expires_at');
      window.location.href = '/login';
    }
  };

  return (
    <AuthContext.Provider
      value={{
        user,
        token,
        sessionExpiresAt,
        isLoading,
        isAdmin,
        isSecurity,
        isStudent,
        login,
        logout,
        refreshUser,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
};
