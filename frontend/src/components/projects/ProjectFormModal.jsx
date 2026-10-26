import { useEffect, useState } from 'react';
import { Button } from '../ui/Button';
import { Select, TextArea, TextField } from '../ui/Field';
import { Modal } from '../ui/Modal';
import { useReferenceData } from '../../hooks/useReferenceData';
import { useToast } from '../../hooks/useToast';
import { contractsService } from '../../services/contractsService';
import { projectsService } from '../../services/projectsService';
import { formatCalendarDate } from '../../utils/format';
import formStyles from '../ui/Form.module.scss';

const EMPTY = { contract_id: '', leader_id: '', name: '', kind: 'project', site_id: '', start_date: '', end_date: '', budget_hours: '', description: '' };

/**
 * Crear o editar un proyecto. Al crear se escoge el contrato (en borrador o
 * activo) y el líder; al editar esos dos no se tocan aquí: el contrato no
 * cambia nunca y el líder tiene su propia acción.
 */
export function ProjectFormModal({ open, project, presetContractId, onClose, onSaved }) {
  const editing = Boolean(project);
  const toast = useToast();
  const reference = useReferenceData();
  const [form, setForm] = useState(EMPTY);
  const [contracts, setContracts] = useState([]);
  const [leaders, setLeaders] = useState([]);
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setFormError(null);
    setForm(
      editing
        ? {
            ...EMPTY,
            name: project.name,
            kind: project.kind.value,
            site_id: String(project.site?.id ?? ''),
            start_date: project.start_date,
            end_date: project.end_date ?? '',
            budget_hours: project.budget_minutes ? String(project.budget_minutes / 60) : '',
            description: project.description ?? '',
          }
        : { ...EMPTY, contract_id: presetContractId ? String(presetContractId) : '' },
    );
  }, [open, editing, project, presetContractId]);

  // Solo al crear: contratos donde se puede abrir un proyecto y posibles líderes.
  useEffect(() => {
    if (!open || editing) return undefined;
    const controller = new AbortController();
    const options = { signal: controller.signal };

    Promise.all([
      contractsService.list({ status: 'active', per_page: 100 }, options),
      contractsService.list({ status: 'draft', per_page: 100 }, options),
      projectsService.leaders(options),
    ])
      .then(([active, drafts, people]) => {
        setContracts([...active.data, ...drafts.data].sort((a, b) => a.code.localeCompare(b.code)));
        setLeaders(people);
      })
      .catch((error) => error.name !== 'AbortError' && setFormError(error.message));

    return () => controller.abort();
  }, [open, editing]);

  const contract = contracts.find((c) => String(c.id) === form.contract_id);

  function set(key) {
    return (e) => setForm((f) => ({ ...f, [key]: e.target.value }));
  }

  function chooseContract(e) {
    const chosen = contracts.find((c) => String(c.id) === e.target.value);
    // Sugerencias razonables: el proyecto arranca con el contrato y una
    // mensualidad suele ser un servicio continuo. Todo se puede cambiar.
    setForm((f) => ({
      ...f,
      contract_id: e.target.value,
      kind: chosen?.billing_model.value === 'monthly_recurring' ? 'ongoing_service' : 'project',
      start_date: f.start_date || chosen?.start_date || '',
      end_date: f.end_date || chosen?.end_date || '',
      name: f.name || chosen?.name || '',
    }));
  }

  function validate() {
    const next = {};
    if (!editing && !form.contract_id) next.contract_id = 'Escoge el contrato del que cuelga el proyecto.';
    if (form.name.trim().length < 3) next.name = 'Escribe un nombre de al menos 3 caracteres.';
    if (!form.site_id) next.site_id = 'Indica la sede que atiende el proyecto.';
    if (!form.start_date) next.start_date = 'Indica la fecha de inicio.';
    if (form.end_date && form.start_date && form.end_date < form.start_date) next.end_date = 'La fecha de fin no puede ser anterior al inicio.';
    if (form.budget_hours !== '' && !(Number(form.budget_hours) >= 1)) next.budget_hours = 'Escribe un número de horas mayor a cero, o déjalo vacío.';
    setErrors(next);
    return Object.keys(next).length === 0;
  }

  async function handleSubmit(event) {
    event.preventDefault();
    if (!validate()) return;

    const payload = {
      name: form.name.trim(),
      kind: form.kind,
      site_id: Number(form.site_id),
      start_date: form.start_date,
      end_date: form.end_date || null,
      budget_hours: form.budget_hours === '' ? null : Number(form.budget_hours),
      description: form.description.trim() || null,
    };

    if (!editing) {
      payload.contract_id = Number(form.contract_id);
      payload.leader_id = form.leader_id ? Number(form.leader_id) : null;
    }

    setSaving(true);
    setFormError(null);
    try {
      const response = editing ? await projectsService.update(project.id, payload) : await projectsService.create(payload);
      toast.success(response.message);
      onSaved(response.data);
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
      size="lg"
      title={editing ? 'Editar proyecto' : 'Nuevo proyecto'}
      description={editing ? `${project.code} · ${project.contract?.code}` : 'Nace como planeado. Se inicia cuando el contrato esté activo.'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancelar
          </Button>
          <Button type="submit" form="project-form" loading={saving}>
            {editing ? 'Guardar cambios' : 'Crear proyecto'}
          </Button>
        </>
      }
    >
      <form id="project-form" onSubmit={handleSubmit} noValidate className={formStyles.form}>
        {formError && (
          <p role="alert" className={formStyles.formError}>
            {formError}
          </p>
        )}

        {!editing && (
          <div className={formStyles.grid}>
            <Select
              label="Contrato"
              value={form.contract_id}
              onChange={chooseContract}
              error={errors.contract_id}
              hint={contract ? `${contract.client.name} · vigente desde ${formatCalendarDate(contract.start_date)}${contract.end_date ? ` hasta ${formatCalendarDate(contract.end_date)}` : ''}` : 'Contratos activos o en borrador.'}
              options={[{ value: '', label: 'Escoge un contrato' }, ...contracts.map((c) => ({ value: String(c.id), label: `${c.code} · ${c.client.name} · ${c.name}` }))]}
            />
            <Select
              label="Líder"
              value={form.leader_id}
              onChange={set('leader_id')}
              error={errors.leader_id}
              hint="Aprueba las horas y gestiona el equipo."
              options={[{ value: '', label: 'Sin líder por ahora' }, ...leaders.map((l) => ({ value: String(l.id), label: l.position ? `${l.name} · ${l.position}` : l.name }))]}
            />
          </div>
        )}

        <TextField label="Nombre" value={form.name} onChange={set('name')} error={errors.name} required maxLength={150} />

        <div className={formStyles.grid}>
          <Select label="Tipo" value={form.kind} onChange={set('kind')} error={errors.kind} options={reference.options('project_kinds')} hint="Un servicio continuo no tiene fecha de fin." />
          <Select label="Sede" value={form.site_id} onChange={set('site_id')} error={errors.site_id} options={reference.options('sites', 'Escoge la sede')} />
          <TextField label="Inicio" type="date" value={form.start_date} onChange={set('start_date')} error={errors.start_date} required />
          <TextField label="Fin" type="date" value={form.end_date} onChange={set('end_date')} error={errors.end_date} hint="Opcional." />
        </div>

        <TextField
          label="Presupuesto de horas"
          type="number"
          min="1"
          step="0.25"
          inputMode="decimal"
          value={form.budget_hours}
          onChange={set('budget_hours')}
          error={errors.budget_hours}
          hint="Opcional. Sin presupuesto propio, un proyecto de bolsa se mide contra la bolsa del contrato."
        />

        <TextArea label="Descripción" value={form.description} onChange={set('description')} error={errors.description} maxLength={1000} hint="Alcance, entregables o notas para el equipo." />
      </form>
    </Modal>
  );
}
