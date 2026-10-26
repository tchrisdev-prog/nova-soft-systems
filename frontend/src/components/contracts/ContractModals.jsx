import { useEffect, useState } from 'react';
import { Button } from '../ui/Button';
import { Select, TextArea, TextField } from '../ui/Field';
import { Modal } from '../ui/Modal';
import { useReferenceData } from '../../hooks/useReferenceData';
import { useToast } from '../../hooks/useToast';
import { contractsService } from '../../services/contractsService';
import { formatCurrency, formatHours, todayInBogota } from '../../utils/format';
import formStyles from '../ui/Form.module.scss';
import { OptionCards } from './OptionCards';
import styles from './Contracts.module.scss';

const toMinutes = (hours) => Math.round(Number(hours) * 4) * 15;

const STATUS_TEXT = {
  active: 'Se pueden registrar horas en sus proyectos. En una mensualidad se abre el mes en curso.',
  suspended: 'Se pausa el servicio: no se registran horas nuevas. Se puede reactivar después.',
  finished: 'El servicio terminó según lo pactado. Ya no se puede reabrir.',
  cancelled: 'Se cancela antes de terminar. Ya no se puede reabrir.',
};

function useSubmit(onSaved) {
  const toast = useToast();
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState(null);

  async function submit(action) {
    setSaving(true);
    setFormError(null);
    try {
      const response = await action();
      toast.success(response.message);
      onSaved(response.data);
    } catch (error) {
      if (error.code === 'validation_failed') setErrors(error.fieldErrors);
      else setFormError(error.message);
    } finally {
      setSaving(false);
    }
  }

  return { saving, errors, setErrors, formError, setFormError, submit };
}

function FormError({ message }) {
  return message ? (
    <p role="alert" className={formStyles.formError}>
      {message}
    </p>
  ) : null;
}

/** Cambiar el estado: solo ofrece los estados a los que el contrato puede pasar. */
export function StatusChangeModal({ open, contract, onClose, onSaved }) {
  const [status, setStatus] = useState('');
  const [note, setNote] = useState('');
  const { saving, errors, setErrors, formError, setFormError, submit } = useSubmit(onSaved);
  const needsNote = status === 'suspended' || status === 'cancelled';

  useEffect(() => {
    if (open) {
      setStatus(contract?.next_statuses?.[0]?.value ?? '');
      setNote('');
      setErrors({});
      setFormError(null);
    }
  }, [open, contract, setErrors, setFormError]);

  function handleSubmit(event) {
    event.preventDefault();
    if (needsNote && note.trim().length < 5) {
      setErrors({ note: 'Explica el motivo (mínimo 5 caracteres). Queda en el historial del contrato.' });
      return;
    }
    submit(() => contractsService.setStatus(contract.id, status, note.trim()));
  }

  const options = (contract?.next_statuses ?? []).map((s) => ({ ...s, description: STATUS_TEXT[s.value] }));

  return (
    <Modal
      open={open}
      onClose={saving ? () => {} : onClose}
      dismissible={!saving}
      title="Cambiar estado del contrato"
      description={contract ? `${contract.code} · hoy está ${contract.status.label.toLowerCase()}.` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancelar
          </Button>
          <Button type="submit" form="status-form" loading={saving} disabled={!status}>
            Cambiar estado
          </Button>
        </>
      }
    >
      <form id="status-form" onSubmit={handleSubmit} noValidate className={formStyles.form}>
        <FormError message={formError} />
        <OptionCards legend="Nuevo estado" name="status" value={status} onChange={setStatus} options={options} error={errors.status} columns={1} />
        <TextArea
          label={needsNote ? 'Motivo' : 'Motivo (opcional)'}
          value={note}
          onChange={(e) => setNote(e.target.value)}
          error={errors.note}
          required={needsNote}
          maxLength={500}
          hint="Queda en el historial del contrato."
        />
      </form>
    </Modal>
  );
}

/** Recargar o ajustar una bolsa de horas. Nunca se edita la bolsa: cada movimiento es una línea nueva. */
export function HourMovementModal({ open, contract, onClose, onSaved }) {
  const [values, setValues] = useState({});
  const { saving, errors, setErrors, formError, setFormError, submit } = useSubmit(onSaved);
  const isTopUp = values.type === 'top_up';

  useEffect(() => {
    if (open) {
      setValues({ type: 'top_up', direction: 'add', hours: '', amount: '', reference_date: todayInBogota(), note: '' });
      setErrors({});
      setFormError(null);
    }
  }, [open, setErrors, setFormError]);

  const set = (field, value) => setValues((v) => ({ ...v, [field]: value }));

  function handleSubmit(event) {
    event.preventDefault();
    const clientErrors = {};
    if (!(Number(values.hours) > 0)) clientErrors.minutes = 'Indica cuántas horas.';
    if (values.amount === '' || Number(values.amount) < 0) clientErrors.amount = 'Indica el valor (puede ser 0).';
    if (!isTopUp && values.note.trim().length < 5) clientErrors.note = 'Explica el motivo del ajuste.';
    setErrors(clientErrors);
    if (Object.keys(clientErrors).length) return;

    const sign = isTopUp || values.direction === 'add' ? 1 : -1;
    submit(() =>
      contractsService.addMovement(contract.id, {
        type: values.type,
        minutes: sign * toMinutes(values.hours),
        amount: sign * Number(values.amount),
        reference_date: values.reference_date,
        note: values.note.trim() || null,
      }),
    );
  }

  return (
    <Modal
      open={open}
      onClose={saving ? () => {} : onClose}
      dismissible={!saving}
      title="Movimiento de la bolsa de horas"
      description={contract?.consumption ? `Saldo actual: ${formatHours(contract.consumption.balance_minutes)} de ${formatHours(contract.consumption.purchased_minutes)} compradas.` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancelar
          </Button>
          <Button type="submit" form="movement-form" loading={saving}>
            Registrar movimiento
          </Button>
        </>
      }
    >
      <form id="movement-form" onSubmit={handleSubmit} noValidate className={formStyles.form}>
        <FormError message={formError} />
        <OptionCards
          legend="Tipo de movimiento"
          name="movement_type"
          value={values.type}
          onChange={(v) => set('type', v)}
          options={[
            { value: 'top_up', label: 'Recarga', description: 'El cliente compra más horas para este contrato.' },
            { value: 'adjustment', label: 'Ajuste', description: 'Corrige un error en la bolsa. Puede sumar o restar y exige un motivo.' },
          ]}
        />
        <div className={styles.grid}>
          {!isTopUp && (
            <Select
              label="El ajuste"
              value={values.direction}
              onChange={(e) => set('direction', e.target.value)}
              options={[
                { value: 'add', label: 'Suma horas' },
                { value: 'subtract', label: 'Resta horas' },
              ]}
            />
          )}
          <TextField label="Horas" type="number" min="0.25" step="0.25" value={values.hours ?? ''} onChange={(e) => set('hours', e.target.value)} error={errors.minutes} required />
          <TextField
            label="Valor (COP)"
            type="number"
            min="0"
            step="1000"
            value={values.amount ?? ''}
            onChange={(e) => set('amount', e.target.value)}
            error={errors.amount}
            hint={values.amount !== '' && values.amount !== undefined ? formatCurrency(Number(values.amount)) : 'Valor de la compra o de la corrección.'}
            required
          />
          <TextField label="Fecha" type="date" value={values.reference_date ?? ''} onChange={(e) => set('reference_date', e.target.value)} error={errors.reference_date} required />
          <TextArea
            className={styles.full}
            label={isTopUp ? 'Nota (opcional)' : 'Motivo del ajuste'}
            value={values.note ?? ''}
            onChange={(e) => set('note', e.target.value)}
            error={errors.note}
            maxLength={500}
            placeholder={isTopUp ? 'Por ejemplo: Orden de compra OC-2026-118' : undefined}
            required={!isTopUp}
          />
        </div>
      </form>
    </Modal>
  );
}

/** Editar condiciones del contrato. El cliente, el código y la forma de cobro no cambian. */
export function ContractEditModal({ open, contract, onClose, onSaved }) {
  const reference = useReferenceData();
  const [values, setValues] = useState({});
  const { saving, errors, setErrors, formError, setFormError, submit } = useSubmit(onSaved);
  const isMonthly = contract?.billing_model.value === 'monthly_recurring';

  useEffect(() => {
    if (open && contract) {
      setValues({
        name: contract.name,
        service_line: contract.service_line.value,
        coverage: contract.coverage.value,
        start_date: contract.start_date,
        end_date: contract.end_date ?? '',
        monthly_fee: contract.monthly_fee ?? '',
        included_hours: contract.included_minutes_per_month !== null ? String(contract.included_minutes_per_month / 60) : '',
        notes: contract.notes ?? '',
        note: '',
      });
      setErrors({});
      setFormError(null);
    }
  }, [open, contract, setErrors, setFormError]);

  const set = (field, value) => setValues((v) => ({ ...v, [field]: value }));

  function handleSubmit(event) {
    event.preventDefault();
    submit(() =>
      contractsService.update(contract.id, {
        name: values.name.trim(),
        service_line: values.service_line,
        coverage: values.coverage,
        start_date: values.start_date,
        end_date: values.end_date || null,
        notes: values.notes.trim() || null,
        note: values.note.trim() || null,
        ...(isMonthly
          ? { monthly_fee: Number(values.monthly_fee), included_minutes_per_month: values.included_hours === '' ? null : toMinutes(values.included_hours) }
          : {}),
      }),
    );
  }

  return (
    <Modal
      open={open}
      onClose={saving ? () => {} : onClose}
      dismissible={!saving}
      size="lg"
      title="Editar contrato"
      description="Cada cambio queda en el historial con el valor anterior y el nuevo."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancelar
          </Button>
          <Button type="submit" form="contract-edit-form" loading={saving}>
            Guardar cambios
          </Button>
        </>
      }
    >
      <form id="contract-edit-form" onSubmit={handleSubmit} noValidate className={formStyles.form}>
        <FormError message={formError} />
        <div className={styles.grid}>
          <TextField className={styles.full} label="Nombre" value={values.name ?? ''} onChange={(e) => set('name', e.target.value)} error={errors.name} maxLength={150} required />
          <Select label="Línea de servicio" value={values.service_line ?? ''} onChange={(e) => set('service_line', e.target.value)} options={reference.options('service_lines')} error={errors.service_line} />
          <Select label="Cobertura" value={values.coverage ?? ''} onChange={(e) => set('coverage', e.target.value)} options={reference.options('coverages')} error={errors.coverage} />
          <TextField label="Fecha de inicio" type="date" value={values.start_date ?? ''} onChange={(e) => set('start_date', e.target.value)} error={errors.start_date} />
          <TextField label="Fecha de fin" type="date" value={values.end_date ?? ''} onChange={(e) => set('end_date', e.target.value)} error={errors.end_date} hint="Vacía: sin fecha de fin." />
          {isMonthly && (
            <>
              <TextField
                label="Valor mensual (COP)"
                type="number"
                min="0"
                step="1000"
                value={values.monthly_fee ?? ''}
                onChange={(e) => set('monthly_fee', e.target.value)}
                error={errors.monthly_fee}
                hint="Un cambio de valor aplica desde el próximo mes; el mes en curso conserva sus condiciones."
              />
              <TextField
                label="Horas incluidas al mes"
                type="number"
                min="0"
                step="0.25"
                value={values.included_hours ?? ''}
                onChange={(e) => set('included_hours', e.target.value)}
                error={errors.included_minutes_per_month}
                hint="Vacío: solo disponibilidad."
              />
            </>
          )}
          <TextArea className={styles.full} label="Notas del contrato" value={values.notes ?? ''} onChange={(e) => set('notes', e.target.value)} error={errors.notes} maxLength={1000} rows={2} />
          <TextArea
            className={styles.full}
            label="Motivo del cambio"
            value={values.note ?? ''}
            onChange={(e) => set('note', e.target.value)}
            error={errors.note}
            maxLength={500}
            rows={2}
            hint="Opcional, pero ayuda a entender el historial. Por ejemplo: «El cliente amplía la cobertura»."
          />
        </div>
      </form>
    </Modal>
  );
}
