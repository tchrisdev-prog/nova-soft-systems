// Catálogo público de módulos para la landing page. Refleja el catálogo del
// backend (backend/config/nova.php). Dentro del panel, la información real
// (estado y acceso) siempre proviene de la API (/api/v1/me).
//
// status: 'active' = disponible hoy · 'planned' = en desarrollo

export const MODULE_ICONS = {
  access_control: 'shield',
  users: 'users',
  audit: 'activity',
  clients: 'briefcase',
  projects: 'folder',
  timesheets: 'clock',
};

// El orden define la posición en el tablero de la landing (3 columnas):
// el núcleo de acceso queda al centro de la primera fila.
export const PUBLIC_MODULES = [
  {
    code: 'users',
    symbol: 'Us',
    name: 'Usuarios',
    status: 'active',
    description: 'Autoriza cuentas corporativas, asigna roles y controla su estado.',
  },
  {
    code: 'access_control',
    symbol: 'Rp',
    name: 'Roles y permisos',
    status: 'active',
    core: true,
    description: 'Define qué puede consultar, crear o administrar cada rol, módulo por módulo.',
  },
  {
    code: 'audit',
    symbol: 'Au',
    name: 'Auditoría',
    status: 'active',
    description: 'Registro de quién cambió roles, permisos, contratos y accesos, y cuándo.',
  },
  {
    code: 'clients',
    symbol: 'Cc',
    name: 'Clientes y contratos',
    status: 'active',
    description: 'Contratos por línea de servicio, bolsas de horas, mensualidades e historial de cada cambio.',
  },
  {
    code: 'projects',
    symbol: 'Pc',
    name: 'Proyectos y capacidad',
    status: 'active',
    description: 'Proyectos y servicios continuos, asignación de consultores y disponibilidad por sede.',
  },
  {
    code: 'timesheets',
    symbol: 'Hr',
    name: 'Registro de horas',
    status: 'planned',
    description: 'Horas 24/7 con aprobación del líder y consumo de cada contrato en tiempo real.',
  },
];

export const STATUS_LABELS = {
  active: 'Disponible',
  planned: 'En desarrollo',
  inactive: 'Inactivo',
};

export const ACTION_LABELS = {
  view: 'Consultar',
  create: 'Crear',
  update: 'Actualizar',
  delete: 'Eliminar',
  manage: 'Administrar',
  // Acciones propias del módulo de proyectos
  lead: 'Liderar',
  capacity: 'Ver capacidad',
  staff: 'Gestionar perfiles',
  financials: 'Ver costos',
  approve: 'Aprobar',
  approve_any: 'Aprobar cualquiera',
};

// Columnas fijas de la matriz de permisos. Las demás acciones de un módulo
// se muestran en la columna "Acciones del módulo".
export const ACTION_ORDER = ['view', 'create', 'update', 'delete', 'manage'];

export function actionLabel(action) {
  return ACTION_LABELS[action] ?? action;
}

/** Ordena las acciones: primero las estándar y luego las propias del módulo. */
export function sortActions(actions) {
  const rank = (a) => (ACTION_ORDER.includes(a) ? ACTION_ORDER.indexOf(a) : ACTION_ORDER.length);
  return [...actions].sort((a, b) => rank(a) - rank(b));
}
