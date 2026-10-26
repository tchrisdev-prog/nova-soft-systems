import { api } from './apiClient';

export const catalogService = {
  modules: (options) => api.get('/api/v1/modules', options).then((r) => r.data),
  /** Permisos agrupados por módulo. */
  permissions: (options) => api.get('/api/v1/permissions', options).then((r) => r.data),
  dashboard: (options) => api.get('/api/v1/dashboard', options).then((r) => r.data),
  auditLogs: (params, options) => api.get('/api/v1/audit-logs', { ...options, query: params }),
};
