// Rutas del panel para cada módulo implementado. Un módulo sin ruta aquí
// se muestra como "en desarrollo" aunque exista en el catálogo.
export const MODULE_ROUTES = {
  clients: '/app/clients',
  projects: '/app/projects',
  access_control: '/app/roles',
  users: '/app/users',
  audit: '/app/audit',
};

// Módulos de administración de la plataforma; los demás son de operación del negocio.
export const ADMIN_MODULES = new Set(['access_control', 'users', 'audit']);

// Otras rutas que pertenecen al mismo módulo (para resaltar el menú).
export const MODULE_EXTRA_PATHS = {
  clients: ['/app/contracts'],
  projects: ['/app/capacity', '/app/staff'],
};
