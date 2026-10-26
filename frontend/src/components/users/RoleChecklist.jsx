import { Checkbox } from '../ui/Field';
import { Spinner } from '../ui/Spinner';
import formStyles from '../ui/Form.module.scss';

export function RoleChecklist({ roles, selected, onChange, loading, error, legend = 'Roles', hint }) {
  function toggle(id) {
    const next = new Set(selected);
    next.has(id) ? next.delete(id) : next.add(id);
    onChange(next);
  }

  return (
    <fieldset className={formStyles.fieldset}>
      <legend className={formStyles.legend}>
        {legend}
        {hint && <span className={formStyles.legendHint}>{hint}</span>}
      </legend>
      {loading ? (
        <p>
          <Spinner size={14} /> Cargando roles…
        </p>
      ) : error ? (
        <p className={formStyles.formError}>{error.message}</p>
      ) : roles.length === 0 ? (
        <p className={formStyles.legendHint}>No hay roles activos para asignar. Crea uno en Roles y permisos.</p>
      ) : (
        <div className={formStyles.options}>
          {roles.map((role) => (
            <Checkbox
              key={role.id}
              checked={selected.has(role.id)}
              onChange={() => toggle(role.id)}
              label={role.is_system ? `${role.name} (acceso total)` : role.name}
              description={role.description}
            />
          ))}
        </div>
      )}
    </fieldset>
  );
}
