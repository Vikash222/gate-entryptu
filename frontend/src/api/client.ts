import axios, { AxiosError } from 'axios';
import type { ApiResponse } from '../types';

export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '/api/v1';

export const apiClient = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

// Interceptor to inject Sanctum auth token
apiClient.interceptors.request.use((config) => {
  const token = localStorage.getItem('smartgate_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Interceptor to catch 401s and format friendly errors
apiClient.interceptors.response.use(
  (response) => response,
  (error: AxiosError<ApiResponse>) => {
    if (error.response?.status === 401) {
      // Don't auto-redirect if checking 2fa or on login page
      const currentPath = window.location.pathname;
      if (!currentPath.includes('/login') && !currentPath.includes('/auth/2fa')) {
        localStorage.removeItem('smartgate_token');
        localStorage.removeItem('smartgate_user');
        window.location.href = '/login';
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
    if (error.code === 'ERR_NETWORK') {
      return 'Unable to reach the SmartGate server. Please check your network connection.';
    }
  }
  if (error instanceof Error) {
    return error.message;
  }
  return 'An unexpected error occurred. Please try again.';
}
