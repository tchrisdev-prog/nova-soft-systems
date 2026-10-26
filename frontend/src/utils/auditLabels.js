export const AUDIT_ACTIONS = {
  'auth.login': 'Inicio de sesión',
  'auth.logout': 'Cierre de sesión',
  'auth.login_rejected': 'Ingreso rechazado',
  'user.authorized': 'Cuenta autorizada',
  'user.auto_provisioned': 'Cuenta registrada automáticamente',
  'user.status_changed': 'Estado de usuario cambiado',
  'user.roles_synced': 'Roles de usuario actualizados',
  'user.deleted': 'Cuenta eliminada',
  'user.restored': 'Cuenta recuperada',
  'role.created': 'Rol creado',
  'role.updated': 'Rol editado',
  'role.status_changed': 'Estado de rol cambiado',
  'role.permissions_synced': 'Permisos de rol actualizados',
  'super_admin.provisioned': 'Super Admin aprovisionado',
  'projects.setup': 'Módulo de proyectos preparado',
  'client.created': 'Cliente registrado',
  'client.updated': 'Cliente editado',
  'client.deleted': 'Cliente eliminado',
  'contract.created': 'Contrato registrado',
  'contract.updated': 'Contrato editado',
  'contract.status_changed': 'Estado de contrato cambiado',
  'contract.hours_added': 'Recarga de bolsa de horas',
  'contract.hours_adjusted': 'Ajuste de bolsa de horas',
  'contract.deleted': 'Contrato eliminado',
  'allocation.created': 'Consultor asignado',
  'time_entries.approved': 'Horas aprobadas',
  'time_entries.rejected': 'Horas rechazadas',
};

const CONTRACT_STATUS = {
  draft: 'borrador',
  active: 'activo',
  suspended: 'suspendido',
  finished: 'finalizado',
  cancelled: 'cancelado',
};

const hours = (minutes) => `${(minutes / 60).toLocaleString('es-CO', { maximumFractionDigits: 2 })} h`;

const REJECTION_REASONS = {
  not_authorized: 'cuenta no autorizada',
  account_inactive: 'cuenta desactivada',
  account_mismatch: 'identidad distinta',
  tenant_mismatch: 'otro tenant',
  invalid_token: 'token inválido',
};

export function auditActionLabel(action) {
  return AUDIT_ACTIONS[action] ?? action;
}

/** Resumen legible de los metadatos de un evento. */
export function auditSummary(log) {
  const m = log.metadata ?? {};
  switch (log.action) {
    case 'user.status_changed':
    case 'role.status_changed':
      return m.is_active ? 'Activado' : 'Desactivado';
    case 'user.roles_synced':
    case 'role.permissions_synced': {
      const parts = [];
      if (m.added?.length) parts.push(`Agregados: ${m.added.join(', ')}`);
      if (m.removed?.length) parts.push(`Retirados: ${m.removed.join(', ')}`);
      return parts.join('. ') || 'Sin cambios';
    }
    case 'role.updated':
      return Object.keys(m.after ?? {})
        .map((field) => (field === 'name' ? `Nombre: ${m.after.name}` : 'Descripción actualizada'))
        .join('. ');
    case 'auth.login_rejected':
      return `${m.email ?? ''} (${REJECTION_REASONS[m.reason] ?? m.reason})`;
    case 'user.deleted':
      return [m.email, m.removed_roles?.length ? `Roles retirados: ${m.removed_roles.join(', ')}` : null].filter(Boolean).join(' · ');
    case 'user.authorized':
    case 'user.restored':
    case 'super_admin.provisioned':
      return m.email ?? '';
    case 'role.created':
      return m.name ?? '';
    case 'client.created':
    case 'client.deleted':
      return [m.code, m.name].filter(Boolean).join(' · ');
    case 'contract.created':
    case 'contract.deleted':
      return m.code ?? '';
    case 'contract.status_changed':
      return `De ${CONTRACT_STATUS[m.from] ?? m.from} a ${CONTRACT_STATUS[m.to] ?? m.to}`;
    case 'contract.hours_added':
    case 'contract.hours_adjusted':
      return m.minutes != null ? `${m.minutes > 0 ? '+' : ''}${hours(m.minutes)}` : '';
    case 'allocation.created':
      return `${m.project ?? ''}: ${m.percent ?? '?'} %${m.overallocated ? ` (queda al ${m.peak_percent} %, sobreasignado)` : ''}`;
    case 'time_entries.approved':
      return `${m.count} registros · ${hours(m.minutes ?? 0)}`;
    case 'time_entries.rejected':
      return `${m.count} registros · ${m.reason ?? ''}`;
    default:
      return '';
  }
}
