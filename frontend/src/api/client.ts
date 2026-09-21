import axios, { AxiosError } from 'axios';
import type { ApiResponse } from '../types';

export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '/api/v1';

export const apiClient = axios.create({
  baseURL: API_BASE_URL,
  timeout: 15000,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

// Interceptor to inject Sanctum auth token and enforce endpoint-specific timeouts
apiClient.interceptors.request.use((config) => {
  const token = localStorage.getItem('smartgate_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }

  // Adjust timeout based on operation profile if not explicitly customized
  if (config.timeout === undefined || config.timeout === 15000) {
    const url = config.url || '';
    if (url.includes('/export') || url.includes('/reports/')) {
      config.timeout = 120000; // Admin report/export longer operation window
    } else if (url.includes('/gate-entry/') || url.includes('/manual-movement')) {
      config.timeout = 60000; // Student and Security movement 60s confirmation window
    } else if (config.method?.toLowerCase() === 'get') {
      config.timeout = 0; // Normal GET: no short artificial timeout
    } else {
      config.timeout = 60000;
    }
  }

  return config;
});

// Interceptor to catch 401s, handle silent refresh, and format friendly errors

let isRefreshing = false;
let failedQueue: Array<{ resolve: (value?: unknown) => void; reject: (reason?: any) => void }> = [];

const processQueue = (error: Error | null, token: string | null = null) => {
  failedQueue.forEach((prom) => {
    if (error) {
      prom.reject(error);
    } else {
      prom.resolve(token);
    }
  });
  failedQueue = [];
};

apiClient.interceptors.response.use(
  (response) => response,
  async (error: AxiosError<ApiResponse>) => {
    const originalRequest = error.config as any;

    if (error.response?.status === 401 && originalRequest && !originalRequest._retry) {
      const currentPath = window.location.pathname;
      
      // Do not attempt refresh on login or 2FA pages, or if it's the refresh endpoint itself failing
      if (currentPath.includes('/login') || currentPath.includes('/auth/2fa') || originalRequest.url?.includes('/auth/refresh')) {
        const errorMsg = (error.response?.data as any)?.message;
        if (errorMsg && (errorMsg.includes('8-hour') || errorMsg.includes('session has expired'))) {
          sessionStorage.setItem('smartgate_session_expired_message', 'Your 8-hour session has expired. Please sign in again.');
        }
        localStorage.removeItem('smartgate_token');
        localStorage.removeItem('smartgate_user');
        localStorage.removeItem('smartgate_session_expires_at');
        window.location.href = '/login';
        return Promise.reject(error);
      }

      if (isRefreshing) {
        return new Promise(function (resolve, reject) {
          failedQueue.push({ resolve, reject });
        })
          .then((token) => {
            originalRequest.headers.Authorization = 'Bearer ' + token;
            return apiClient(originalRequest);
          })
          .catch((err) => {
            return Promise.reject(err);
          });
      }

      originalRequest._retry = true;
      isRefreshing = true;

      try {
        // Use a generic axios instance to avoid infinite interceptor loops
        const oldToken = localStorage.getItem('smartgate_token');
        const { data } = await axios.post(`${API_BASE_URL}/auth/refresh`, {}, {
          headers: { Authorization: `Bearer ${oldToken}` }
        });

        const newToken = data.data.token;
        localStorage.setItem('smartgate_token', newToken);

        // Update the failed request
        originalRequest.headers.Authorization = `Bearer ${newToken}`;
        
        processQueue(null, newToken);
        return apiClient(originalRequest);
      } catch (err) {
        processQueue(err as Error, null);
        
        // Refresh failed (token truly invalid, revoked, or 8-hour session expired)
        const axiosErr = err as AxiosError<ApiResponse>;
        const errMsg = axiosErr.response?.data?.message;
        const expiresAt = localStorage.getItem('smartgate_session_expires_at');
        if ((errMsg && (errMsg.includes('8-hour') || errMsg.includes('session has expired'))) || (expiresAt && new Date(expiresAt).getTime() <= Date.now())) {
          sessionStorage.setItem('smartgate_session_expired_message', 'Your 8-hour session has expired. Please sign in again.');
        }

        localStorage.removeItem('smartgate_token');
        localStorage.removeItem('smartgate_user');
        localStorage.removeItem('smartgate_session_expires_at');
        window.location.href = '/login';
        
        return Promise.reject(err);
      } finally {
        isRefreshing = false;
      }
    }

    return Promise.reject(error);
  }
);

export function getErrorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as ApiResponse | undefined;
    if (data?.message) {
      if (data.errors && Object.keys(data.errors).length > 0) {
        const firstErrorKey = Object.keys(data.errors)[0];
        const firstErrorMessage = data.errors[firstErrorKey][0];
        return `${data.message}: ${firstErrorMessage}`;
      }
      return data.message;
    }
    if (error.code === 'ECONNABORTED' || error.message?.toLowerCase().includes('timeout')) {
      return 'The request timed out. Please verify your connection and try again.';
    }
    if (error.code === 'ERR_NETWORK') {
      return 'Unable to reach the SmartGate server. Please check your network connection.';
    }
  }
  if (error instanceof Error) {
    if (error.name === 'CanceledError' || error.message?.toLowerCase().includes('cancel')) {
      return 'Request was cancelled.';
    }
    return error.message;
  }
  return 'An unexpected error occurred. Please try again.';
}
