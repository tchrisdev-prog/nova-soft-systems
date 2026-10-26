import { ACTION_LABELS, ACTION_ORDER, actionLabel } from '../../config/modules';
import { Badge } from '../ui/Badge';
import { cx } from '../../utils/cx';
import styles from './PermissionMatrix.module.scss';

/**
 * Matriz módulos × acciones. Cada celda es un checkbox nativo con una
 * etiqueta accesible completa ("Usuarios: Consultar").
 *
 * Las acciones estándar tienen columna propia. Las que solo existen en un
 * módulo (aprobar horas, ver costos...) van en la última columna, con su
 * descripción completa para que quede claro qué se está otorgando.
 */
export function PermissionMatrix({ modules, selected, onToggle, onToggleModule, readOnly }) {
  return (
    <div className={styles.wrapper}>
      <table className={styles.matrix}>
        <caption className="visually-hidden">Permisos por módulo y acción</caption>
        <thead>
          <tr>
            <th scope="col" className={styles.moduleHead}>
              Módulo
            </th>
            {ACTION_ORDER.map((action) => (
              <th key={action} scope="col" className={styles.actionHead}>
                {ACTION_LABELS[action]}
              </th>
            ))}
            <th scope="col" className={styles.extraHead}>
              Acciones del módulo
            </th>
          </tr>
        </thead>
        <tbody>
          {modules.map((module) => {
            const byAction = Object.fromEntries(module.permissions.map((p) => [p.action, p]));
            const ids = module.permissions.map((p) => p.id);
            const checkedCount = ids.filter((id) => selected.has(id)).length;
            const allChecked = checkedCount === ids.length && ids.length > 0;

            return (
              <tr key={module.code} className={cx(module.status !== 'active' && styles.dimmed)}>
                <th scope="row" className={styles.moduleCell}>
                  <span className={styles.moduleName}>{module.name}</span>
                  <span className={styles.moduleMeta}>
                    {module.status !== 'active' && <Badge>{module.status_label}</Badge>}
                    {!readOnly && ids.length > 0 && (
                      <button type="button" className={styles.toggleAll} onClick={() => onToggleModule(ids, !allChecked)}>
                        {allChecked ? 'Quitar todos' : 'Marcar todos'}
                      </button>
                    )}
                  </span>
                </th>
                {ACTION_ORDER.map((action) => {
                  const permission = byAction[action];
                  if (!permission) {
                    return (
                      <td key={action} className={styles.cell}>
                        <span className={styles.na} aria-label="No aplica">
                          —
                        </span>
                      </td>
                    );
                  }
                  const checked = selected.has(permission.id);
                  return (
                    <td key={action} className={styles.cell}>
                      <label className={cx(styles.check, checked && styles.checked, readOnly && styles.readOnly)} title={permission.description ?? undefined}>
                        <input
                          type="checkbox"
                          checked={checked}
                          disabled={readOnly}
                          onChange={() => onToggle(permission.id)}
                          aria-label={`${module.name}: ${ACTION_LABELS[action] ?? action}`}
                        />
                        <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">
                          <path d="m3.5 8.5 3 3 6-7" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" />
                        </svg>
                      </label>
                    </td>
                  );
                })}
                <td className={styles.extraCell}>
                  <ExtraActions
                    module={module}
                    permissions={module.permissions.filter((p) => !ACTION_ORDER.includes(p.action))}
                    selected={selected}
                    onToggle={onToggle}
                    readOnly={readOnly}
                  />
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function ExtraActions({ module, permissions, selected, onToggle, readOnly }) {
  if (permissions.length === 0) {
    return (
      <span className={styles.na} aria-label="No aplica">
        —
      </span>
    );
  }

  return (
    <ul className={styles.extraList}>
      {permissions.map((permission) => {
        const checked = selected.has(permission.id);
        return (
          <li key={permission.id}>
            <label className={cx(styles.extra, readOnly && styles.readOnly)}>
              <span className={cx(styles.check, checked && styles.checked, readOnly && styles.readOnly)}>
                <input
                  type="checkbox"
                  checked={checked}
                  disabled={readOnly}
                  onChange={() => onToggle(permission.id)}
                  aria-label={`${module.name}: ${permission.description ?? actionLabel(permission.action)}`}
                />
                <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">
                  <path d="m3.5 8.5 3 3 6-7" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
              </span>
              <span className={styles.extraText}>{permission.description ?? actionLabel(permission.action)}</span>
            </label>
          </li>
        );
      })}
    </ul>
  );
}
