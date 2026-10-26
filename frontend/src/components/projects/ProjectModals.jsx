import { useEffect, useState } from 'react';
import { Button } from '../ui/Button';
import { Select, TextArea } from '../ui/Field';
import { Modal } from '../ui/Modal';
import { OptionCards } from '../contracts/OptionCards';
import { useToast } from '../../hooks/useToast';
import { projectsService } from '../../services/projectsService';
import formStyles from '../ui/Form.module.scss';

const STATUS_TEXT = {
  active: 'El equipo puede registrar horas. Requiere que el contrato esté activo.',
  on_hold: 'Se pausa el trabajo y no se registran horas nuevas. Se puede reanudar.',
  closed: 'Se cierra definitivamente y el equipo queda libre desde hoy. No se puede reabrir.',
};

function FormError({ message }) {
  return message ? (
    <p role="alert" className={formStyles.formError}>
      {message}
    </p>
  ) : null;
}

/**
 * Iniciar, pausar o cerrar. Si quedan horas sin resolver, el servidor no deja
 * cerrar y explica cuántas son: aquí solo se muestra ese mensaje.
 */
export function ProjectStatusModal({ open, project, onClose, onSaved }) {
  const toast = useToast();
  const [status, setStatus] = useState('');
  const [note, setNote] = useState('');
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState(null);
  const [saving, setSaving] = useState(false);
  const needsNote = status === 'on_hold';

  useEffect(() => {
    if (open) {
      setStatus(project?.next_statuses?.[0]?.value ?? '');
      setNote('');
      setErrors({});
      setFormError(null);
    }
  }, [open, project]);

  async function handleSubmit(event) {
    event.preventDefault();
    if (needsNote && note.trim().length < 5) {
      setErrors({ note: 'Explica el motivo (mínimo 5 caracteres). Queda en la auditoría.' });
      return;
    }

    setSaving(true);
    setFormError(null);
    try {
      const response = await projectsService.setStatus(project.id, status, note.trim());
      toast.success(response.message);
      onSaved(response.data);
    } catch (error) {
      if (error.code === 'validation_failed') setErrors(error.fieldErrors);
      else setFormError(error.message);
    } finally {
      setSaving(false);
    }
  }

  const options = (project?.next_statuses ?? []).map((s) => ({ ...s, description: STATUS_TEXT[s.value] }));

  return (
    <Modal
      open={open}
      onClose={saving ? () => {} : onClose}
      dismissible={!saving}
      title="Cambiar estado del proyecto"
      description={project ? `${project.code} · hoy está ${project.status.label.toLowerCase()}.` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancelar
          </Button>
          <Button type="submit" form="project-status-form" loading={saving} disabled={!status} variant={status === 'closed' ? 'danger' : 'primary'}>
            {status === 'closed' ? 'Cerrar proyecto' : 'Cambiar estado'}
          </Button>
        </>
      }
    >
      <form id="project-status-form" onSubmit={handleSubmit} noValidate className={formStyles.form}>
        <FormError message={formError} />
        <OptionCards legend="Nuevo estado" name="project-status" value={status} onChange={setStatus} options={options} error={errors.status} columns={1} />
        <TextArea
          label={needsNote ? 'Motivo' : 'Nota (opcional)'}
          value={note}
          onChange={(e) => setNote(e.target.value)}
          error={errors.note}
          required={needsNote}
          maxLength={500}
          hint="Queda en la auditoría del proyecto."
        />
      </form>
    </Modal>
  );
}

/** Cambiar el líder. La lista la arma el servidor: solo quien puede liderar. */
export function LeaderModal({ open, project, onClose, onSaved }) {
  const toast = useToast();
  const [leaders, setLeaders] = useState([]);
  const [leaderId, setLeaderId] = useState('');
  const [formError, setFormError] = useState(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open) return undefined;
    setLeaderId(project?.leader ? String(project.leader.id) : '');
    setFormError(null);
    const controller = new AbortController();
    projectsService
      .leaders({ signal: controller.signal })
      .then(setLeaders)
      .catch((error) => error.name !== 'AbortError' && setFormError(error.message));
    return () => controller.abort();
  }, [open, project]);

  async function handleSubmit(event) {
    event.preventDefault();
    setSaving(true);
    setFormError(null);
    try {
      const response = await projectsService.setLeader(project.id, Number(leaderId));
      toast.success(response.message);
      onSaved(response.data);
    } catch (error) {
      setFormError(error.message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open={open}
      onClose={saving ? () => {} : onClose}
      dismissible={!saving}
      size="sm"
      title="Cambiar líder"
      description="El nuevo líder aprueba las horas del proyecto desde ahora. Lo ya aprobado no cambia."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancelar
          </Button>
          <Button type="submit" form="leader-form" loading={saving} disabled={!leaderId || leaderId === String(project?.leader?.id ?? '')}>
            Cambiar líder
          </Button>
        </>
      }
    >
      <form id="leader-form" onSubmit={handleSubmit} noValidate className={formStyles.form}>
        <FormError message={formError} />
        <Select
          label="Líder"
          value={leaderId}
          onChange={(e) => setLeaderId(e.target.value)}
          options={[{ value: '', label: 'Escoge una persona' }, ...leaders.map((l) => ({ value: String(l.id), label: l.position ? `${l.name} · ${l.position}` : l.name }))]}
        />
      </form>
    </Modal>
  );
}
