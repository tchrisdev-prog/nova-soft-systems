import { useEffect, useMemo, useState } from 'react';
import { Button } from '../ui/Button';
import { Select, TextField } from '../ui/Field';
import { Modal } from '../ui/Modal';
import { useAuth } from '../../hooks/useAuth';
import { useToast } from '../../hooks/useToast';
import { capacityService } from '../../services/capacityService';
import { projectsService } from '../../services/projectsService';
import { formatCalendarDate, todayInBogota } from '../../utils/format';
import formStyles from '../ui/Form.module.scss';
import styles from './Projects.module.scss';

const PERCENTS = Array.from({ length: 20 }, (_, i) => (i + 1) * 5);

/** AAAA-MM-DD + n días, sin pasar por zonas horarias. */
function addDays(date, days) {
  const [y, m, d] = date.split('-').map(Number);
  const next = new Date(Date.UTC(y, m - 1, d + days));
  return next.toISOString().slice(0, 10);
}

/**
 * Asignar una persona a un proyecto, o cambiar una asignación existente.
 *
 * - Desde el proyecto: se escoge la persona viendo cuánto tiene libre en el
 *   rango (lo calcula el servidor con el día más cargado).
 * - Desde la búsqueda de disponibilidad: la persona ya viene escogida y se
 *   escoge el proyecto.
 *
 * Si la persona queda por encima del 100 %, el servidor responde 409 y aquí
 * se pide confirmarlo explícitamente; la confirmación queda en la auditoría.
 */
export function AllocationModal({ open, project, allocation, presetPerson, presetRange, onClose, onSaved }) {
  const editing = Boolean(allocation);
  const { can } = useAuth();
  const toast = useToast();
  const [form, setForm] = useState({});
  const [projects, setProjects] = useState([]);
  const [candidates, setCandidates] = useState(null);
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState(null);
  const [overallocation, setOverallocation] = useState(null);
  const [saving, setSaving] = useState(false);
  const canSearch = can('projects.capacity');

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setFormError(null);
    setOverallocation(null);
    setCandidates(null);
    setForm(
      editing
        ? { allocation_percent: String(allocation.allocation_percent), end_date: allocation.end_date ?? '', role_label: allocation.role_label ?? '' }
        : {
            project_id: project ? String(project.id) : '',
            user_id: presetPerson ? String(presetPerson.id) : '',
            allocation_percent: String(presetRange?.percent || 50),
            start_date: presetRange?.from || todayInBogota(),
            end_date: presetRange?.to || '',
            role_label: '',
          },
    );
  }, [open, editing, allocation, project, presetPerson, presetRange]);

  // Desde la búsqueda de disponibilidad: proyectos abiertos donde puedo asignar.
  useEffect(() => {
    if (!open || editing || project) return undefined;
    const controller = new AbortController();
    projectsService
      .list({ open: 1, per_page: 100 }, { signal: controller.signal })
      .then((response) => setProjects(response.data.filter((p) => p.can.manage_allocations)))
      .catch((error) => error.name !== 'AbortError' && setFormError(error.message));
    return () => controller.abort();
  }, [open, editing, project]);

  // Candidatos con su % libre en el rango escogido (todos, incluso llenos:
  // a veces sobreasignar es una decisión consciente).
  const rangeFrom = form.start_date;
  const rangeTo = form.end_date || (form.start_date ? addDays(form.start_date, 89) : '');
  useEffect(() => {
    if (!open || editing || presetPerson || !canSearch || !rangeFrom || !rangeTo || rangeTo < rangeFrom) return undefined;
    const controller = new AbortController();
    const timer = setTimeout(() => {
      capacityService
        .available({ percent: 0, from: rangeFrom, to: rangeTo }, { signal: controller.signal })
        .then(setCandidates)
        .catch((error) => error.name !== 'AbortError' && setFormError(error.message));
    }, 250);
    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [open, editing, presetPerson, canSearch, rangeFrom, rangeTo]);

  const candidateOptions = useMemo(() => {
    const list = (candidates ?? []).slice().sort((a, b) => a.user.name.localeCompare(b.user.name, 'es'));
    return [
      { value: '', label: candidates ? 'Escoge una persona' : 'Cargando disponibilidad…' },
      ...list.map((c) => ({ value: String(c.user.id), label: `${c.user.name} · ${c.position ?? 'sin cargo'} · ${c.free_percent} % libre` })),
    ];
  }, [candidates]);

  const chosen = candidates?.find((c) => String(c.user.id) === form.user_id);

  function set(key) {
    return (e) => {
      setForm((f) => ({ ...f, [key]: e.target.value }));
      setOverallocation(null);
    };
  }

  function validate() {
    const next = {};
    if (!editing) {
      if (!form.project_id) next.project_id = 'Escoge el proyecto.';
      if (!form.user_id) next.user_id = 'Escoge a la persona.';
      if (!form.start_date) next.start_date = 'Indica desde cuándo.';
    }
    const start = editing ? allocation.start_date : form.start_date;
    if (form.end_date && start && form.end_date < start) next.end_date = 'La fecha de fin no puede ser anterior al inicio.';
    setErrors(next);
    return Object.keys(next).length === 0;
  }

  async function save(confirm = false) {
    if (!validate()) return;

    const payload = editing
      ? { allocation_percent: Number(form.allocation_percent), end_date: form.end_date || null, role_label: form.role_label.trim() || null }
      : {
          user_id: Number(form.user_id),
          allocation_percent: Number(form.allocation_percent),
          start_date: form.start_date,
          end_date: form.end_date || null,
          role_label: form.role_label.trim() || null,
        };
    if (confirm) payload.confirm_overallocation = true;

    const projectId = editing ? allocation.project_id : Number(form.project_id);

    setSaving(true);
    setFormError(null);
    try {
      const response = editing ? await projectsService.updateAllocation(projectId, allocation.id, payload) : await projectsService.addAllocation(projectId, payload);
      toast.success(response.message);
      onSaved(response.data);
    } catch (error) {
      if (error.code === 'overallocation') setOverallocation(error.message);
      else if (error.code === 'validation_failed') setErrors(error.fieldErrors);
      else setFormError(error.message);
    } finally {
      setSaving(false);
    }
  }

  const title = editing ? `Asignación de ${allocation.user?.name ?? ''}` : 'Asignar al proyecto';
  const description = editing
    ? `Desde el ${formatCalendarDate(allocation.start_date)}. La persona y la fecha de inicio no cambian: si entra en otra fecha, termina esta asignación y crea otra.`
    : project
      ? `${project.code} · ${project.name}`
      : presetPerson
        ? `${presetPerson.name}${presetPerson.free_percent !== undefined ? ` · ${presetPerson.free_percent} % libre en el rango buscado` : ''}`
        : undefined;

  return (
    <Modal
      open={open}
      onClose={saving ? () => {} : onClose}
      dismissible={!saving}
      title={title}
      description={description}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancelar
          </Button>
          {overallocation ? (
            <Button variant="danger" loading={saving} onClick={() => save(true)}>
              Asignar de todas formas
            </Button>
          ) : (
            <Button type="submit" form="allocation-form" loading={saving}>
              {editing ? 'Guardar cambios' : 'Asignar'}
            </Button>
          )}
        </>
      }
    >
      <form
        id="allocation-form"
        noValidate
        className={formStyles.form}
        onSubmit={(e) => {
          e.preventDefault();
          save(false);
        }}
      >
        {formError && (
          <p role="alert" className={formStyles.formError}>
            {formError}
          </p>
        )}

        {overallocation && (
          <div className={styles.overNotice} role="alert">
            <strong>{overallocation}</strong>
            <span>Si es una urgencia y es intencional, confírmalo. Quedará registrado en la auditoría con el porcentaje resultante.</span>
          </div>
        )}

        {!editing && !project && (
          <Select
            label="Proyecto"
            value={form.project_id ?? ''}
            onChange={set('project_id')}
            error={errors.project_id}
            hint="Proyectos abiertos donde puedes asignar personas."
            options={[{ value: '', label: projects.length ? 'Escoge un proyecto' : 'Cargando proyectos…' }, ...projects.map((p) => ({ value: String(p.id), label: `${p.code} · ${p.name}` }))]}
          />
        )}

        {!editing && (
          <div className={formStyles.grid}>
            <TextField label="Desde" type="date" value={form.start_date ?? ''} onChange={set('start_date')} error={errors.start_date} required />
            <TextField label="Hasta" type="date" value={form.end_date ?? ''} onChange={set('end_date')} error={errors.end_date} hint="Vacío: sin fecha de fin." />
          </div>
        )}

        {!editing && !presetPerson && (
          canSearch ? (
            <Select
              label="Persona"
              value={form.user_id ?? ''}
              onChange={set('user_id')}
              error={errors.user_id}
              hint={chosen ? `En su día más cargado del rango tiene ${chosen.free_percent} % libre.` : 'El % libre se calcula para el rango de fechas escogido.'}
              options={candidateOptions}
            />
          ) : (
            <p className={formStyles.formError}>Para escoger a la persona necesitas el permiso de ver la capacidad.</p>
          )
        )}

        <div className={formStyles.grid}>
          <Select
            label="Dedicación"
            value={form.allocation_percent ?? '50'}
            onChange={set('allocation_percent')}
            error={errors.allocation_percent}
            hint="Porcentaje de su jornada semanal."
            options={PERCENTS.map((p) => ({ value: String(p), label: `${p} %` }))}
          />
          <TextField label="Rol en el proyecto" value={form.role_label ?? ''} onChange={set('role_label')} error={errors.role_label} maxLength={80} placeholder="Ej.: Backend, QA, Arquitecta" />
        </div>

        {editing && <TextField label="Hasta" type="date" value={form.end_date ?? ''} onChange={set('end_date')} error={errors.end_date} hint="Vacío: sin fecha de fin." />}
      </form>
    </Modal>
  );
}
