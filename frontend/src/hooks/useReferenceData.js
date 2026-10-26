import { useEffect, useState } from 'react';
import { api } from '../services/apiClient';

// Las listas de referencia (líneas de servicio, estados, sedes...) casi no
// cambian: se piden una sola vez por sesión y se comparten entre pantallas.
let cache = null;

export function useReferenceData() {
  const [data, setData] = useState(cache?.data ?? null);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (cache?.data) return undefined;
    let active = true;

    cache ??= { promise: api.get('/api/v1/reference-data').then((response) => (cache.data = response.data)) };
    cache.promise
      .then((value) => active && setData(value))
      .catch((err) => {
        cache = null; // permite reintentar al volver a la pantalla
        if (active) setError(err);
      });

    return () => {
      active = false;
    };
  }, []);

  /** Opciones para un <Select>, con una opción vacía opcional al inicio. */
  function options(key, emptyLabel) {
    const list = (data?.[key] ?? []).map((o) => ({ value: o.value ?? String(o.id), label: o.label ?? o.name }));
    return emptyLabel ? [{ value: '', label: emptyLabel }, ...list] : list;
  }

  function label(key, value) {
    return data?.[key]?.find((o) => o.value === value)?.label ?? value;
  }

  return { data, error, options, label };
}
