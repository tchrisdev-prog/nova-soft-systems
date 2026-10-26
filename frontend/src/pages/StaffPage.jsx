import { useEffect, useState } from 'react';
import { ModuleTabs } from '../components/business/ModuleTabs';
import { Badge } from '../components/ui/Badge';
import { Button } from '../components/ui/Button';
import { Checkbox, Select, TextField } from '../components/ui/Field';
import { Modal } from '../components/ui/Modal';
import { PageHeader } from '../components/ui/PageHeader';
import { Pagination } from '../components/ui/Pagination';
import { SearchInput } from '../components/ui/SearchInput';
import { EmptyState, ErrorState } from '../components/ui/StateMessage';
import { Cell, SkeletonRows, Table } from '../components/ui/Table';
import { useApiResource } from '../hooks/useApiResource';
import { useDebouncedValue } from '../hooks/useDebouncedValue';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import { useReferenceData } from '../hooks/useReferenceData';
import { useToast } from '../hooks/useToast';
import { staffService } from '../services/capacityService';
import { formatCurrency } from '../utils/format';
import formStyles from '../components/ui/Form.module.scss';
import styles from './AdminPage.module.scss';

/**
 * Perfil laboral: sede, cargo, especialidad y jornada. Sin perfil, la persona
 * no aparece en la capacidad. El costo por hora solo lo ve y lo cambia quien
 * tiene el permiso de costos; el servidor ni siquiera lo envía a los demás.
 */
function StaffProfileModal({ open, person, canCost, onClose, onSaved }) {
  const toast = useToast();
  const reference = useReferenceData();
  const [form, setForm] = useState({});
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open || !person) return;
    const p = person.profile;
    setErrors({});
    setFormError(null);
    setForm({
      site_id: p ? String(p.site.id) : '',
      position: p?.position ?? '',
      specialty: p?.specialty?.value ?? '',
      weekly_hours: String(p?.weekly_hours ?? reference.data?.default_weekly_hours ?? 42),
      hourly_cost: p?.hourly_cost != null ? String(p.hourly_cost) : '',
    });
  }, [open, person, reference.data]);

  function set(key) {
    return (e) => setForm((f) => ({ ...f, [key]: e.target.value }));
  }

  async function handleSubmit(event) {
    event.preventDefault();
    const next = {};
    if (!form.site_id) next.site_id = 'Escoge la sede.';
    if (form.position.trim().length < 2) next.position = 'Escribe el cargo.';
    const hours = Number(form.weekly_hours);
    if (!(hours >= 1 && hours <= 60)) next.weekly_hours = 'Entre 1 y 60 horas a la semana.';
    if (canCost && form.hourly_cost !== '' && !(Number(form.hourly_cost) >= 0)) next.hourly_cost = 'Escribe un valor en pesos, sin puntos.';
    setErrors(next);
    if (Object.keys(next).length) return;

    const payload = { site_id: Number(form.site_id), position: form.position.trim(), specialty: form.specialty || null, weekly_hours: hours };
    if (canCost) payload.hourly_cost = form.hourly_cost === '' ? null : Number(form.hourly_cost);

    setSaving(true);
    setFormError(null);
    try {
      const response = await staffService.save(person.user.id, payload);
      toast.success(response.message);
      onSaved();
    } catch (error) {
      if (error.code === 'validation_failed') setErrors(error.fieldErrors);
      else setFormError(error.message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open={open}
      onClose={saving ? () => {} : onClose}
      dismissible={!saving}
      title={person?.profile ? 'Editar perfil laboral' : 'Crear perfil laboral'}
      description={person ? `${person.user.name} · ${person.user.email}` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancelar
          </Button>
          <Button type="submit" form="staff-form" loading={saving}>
            Guardar perfil
          </Button>
        </>
      }
    >
      <form id="staff-form" onSubmit={handleSubmit} noValidate className={formStyles.form}>
        {formError && (
          <p role="alert" className={formStyles.formError}>
            {formError}
          </p>
        )}
        <div className={formStyles.grid}>
          <Select label="Sede" value={form.site_id ?? ''} onChange={set('site_id')} error={errors.site_id} options={reference.options('sites', 'Escoge la sede')} />
          <Select label="Especialidad" value={form.specialty ?? ''} onChange={set('specialty')} error={errors.specialty} options={reference.options('staff_specialties', 'Sin especialidad')} hint="Sirve para buscar disponibilidad." />
        </div>
        <TextField label="Cargo" value={form.position ?? ''} onChange={set('position')} error={errors.position} required maxLength={100} />
        <div className={formStyles.grid}>
          <TextField
            label="Horas por semana"
            type="number"
            min="1"
            max="60"
            step="0.5"
            value={form.weekly_hours ?? ''}
            onChange={set('weekly_hours')}
            error={errors.weekly_hours}
            hint="42 h es la jornada legal desde julio de 2026. Ajústala para medio tiempo o jornadas especiales."
          />
          {canCost && (
            <TextField
              label="Costo por hora (COP)"
              type="number"
              min="0"
              step="1000"
              value={form.hourly_cost ?? ''}
              onChange={set('hourly_cost')}
              error={errors.hourly_cost}
              hint="Interno. Se congela en cada hora al aprobarla."
            />
          )}
        </div>
      </form>
    </Modal>
  );
}

export default function StaffPage() {
  useDocumentTitle('Perfiles laborales');
  const reference = useReferenceData();
  const [search, setSearch] = useState('');
  const [filters, setFilters] = useState({ site_id: '', specialty: '' });
  const [missing, setMissing] = useState(false);
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState(null);
  const debouncedSearch = useDebouncedValue(search.trim());

  const { data, error, loading, reload } = useApiResource(
    (signal) => staffService.list({ search: debouncedSearch, ...filters, missing: missing ? 1 : undefined, page }, { signal }),
    [debouncedSearch, filters, missing, page],
  );

  const canCost = Boolean(data?.can?.manage_cost);
  const rows = data?.data ?? [];
  const columns = [
    { key: 'person', label: 'Persona' },
    { key: 'position', label: 'Cargo y especialidad' },
    { key: 'site', label: 'Sede' },
    { key: 'hours', label: 'Jornada', align: 'end' },
    ...(canCost ? [{ key: 'cost', label: 'Costo por hora', align: 'end' }] : []),
    { key: 'actions', label: 'Acciones', hidden: true, align: 'end' },
  ];

  function setFilter(key) {
    return (e) => {
      setFilters((f) => ({ ...f, [key]: e.target.value }));
      setPage(1);
    };
  }

  return (
    <>
      <PageHeader title="Proyectos y capacidad" description="Sede, cargo, especialidad y jornada de cada persona. Quien entra por primera vez con Microsoft 365 no tiene perfil hasta que se le cree aquí." />
      <ModuleTabs module="projects" />

      <section className={styles.panel} aria-label="Perfiles laborales">
        <div className={styles.toolbar}>
          <SearchInput
            label="Buscar personas"
            value={search}
            onChange={(value) => {
              setSearch(value);
              setPage(1);
            }}
            placeholder="Buscar por nombre o correo"
          />
          <Select label="Sede" className={styles.filter} value={filters.site_id} onChange={setFilter('site_id')} options={reference.options('sites', 'Todas las sedes')} />
          <Select label="Especialidad" className={styles.filter} value={filters.specialty} onChange={setFilter('specialty')} options={reference.options('staff_specialties', 'Todas las especialidades')} />
          <Checkbox
            checked={missing}
            onChange={(e) => {
              setMissing(e.target.checked);
              setPage(1);
            }}
            label={`Sin perfil${data?.meta?.missing_profiles ? ` (${data.meta.missing_profiles})` : ''}`}
          />
        </div>

        {error ? (
          <ErrorState error={error} onRetry={reload} />
        ) : !loading && rows.length === 0 ? (
          <EmptyState icon="users" title={missing ? 'Todos tienen perfil' : 'Sin resultados'} message={missing ? 'Cada persona activa ya tiene su perfil laboral.' : 'Nadie coincide con la búsqueda o los filtros.'} />
        ) : (
          <Table caption="Perfiles laborales" columns={columns}>
            {loading && !data ? (
              <SkeletonRows columns={columns.length} />
            ) : (
              rows.map((row) => (
                <tr key={row.user.id}>
                  <Cell label="Persona">
                    <span className={styles.primaryText}>{row.user.name}</span>
                    <span className={styles.secondary}>{row.user.email}</span>
                  </Cell>
                  <Cell label="Cargo y especialidad">
                    {row.profile ? (
                      <>
                        <span className={styles.primaryText}>{row.profile.position}</span>
                        <span className={styles.secondary}>{row.profile.specialty?.label ?? 'Sin especialidad'}</span>
                      </>
                    ) : (
                      <Badge tone="warning">Sin perfil: no aparece en la capacidad</Badge>
                    )}
                  </Cell>
                  <Cell label="Sede">{row.profile?.site.name ?? <span className={styles.placeholder}>—</span>}</Cell>
                  <Cell label="Jornada" align="end">
                    {row.profile ? <span className="tabular">{row.profile.weekly_hours} h/sem</span> : <span className={styles.placeholder}>—</span>}
                  </Cell>
                  {canCost && (
                    <Cell label="Costo por hora" align="end">
                      {row.profile?.hourly_cost != null ? <span className="tabular">{formatCurrency(row.profile.hourly_cost)}</span> : <span className={styles.placeholder}>Sin costo</span>}
                    </Cell>
                  )}
                  <Cell label="Acciones" align="end">
                    <Button size="sm" variant={row.profile ? 'ghost' : 'secondary'} icon={row.profile ? 'edit' : 'plus'} onClick={() => setEditing(row)}>
                      {row.profile ? 'Editar' : 'Crear perfil'}
                    </Button>
                  </Cell>
                </tr>
              ))
            )}
          </Table>
        )}

        <Pagination meta={data?.meta} onPageChange={setPage} label="personas" />
      </section>

      <StaffProfileModal
        open={Boolean(editing)}
        person={editing}
        canCost={canCost}
        onClose={() => setEditing(null)}
        onSaved={() => {
          setEditing(null);
          reload();
        }}
      />
    </>
  );
}
