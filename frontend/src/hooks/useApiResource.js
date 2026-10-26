import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Carga un recurso remoto con estados de carga, error y recarga.
 * Cancela la petición anterior cuando cambian las dependencias.
 *
 *   const { data, error, loading, reload } = useApiResource((signal) => service.list(params, { signal }), [params]);
 */
export function useApiResource(fetcher, deps = []) {
  const [state, setState] = useState({ data: null, error: null, loading: true });
  const controller = useRef(null);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const run = useCallback(fetcher, deps);

  const load = useCallback(async () => {
    controller.current?.abort();
    const current = new AbortController();
    controller.current = current;
    setState((prev) => ({ ...prev, loading: true, error: null }));

    try {
      const data = await run(current.signal);
      if (!current.signal.aborted) setState({ data, error: null, loading: false });
    } catch (error) {
      if (error.name !== 'AbortError' && !current.signal.aborted) {
        setState((prev) => ({ ...prev, error, loading: false }));
      }
    }
  }, [run]);

  useEffect(() => {
    load();
    return () => controller.current?.abort();
  }, [load]);

  return { ...state, reload: load, setData: (updater) => setState((prev) => ({ ...prev, data: typeof updater === 'function' ? updater(prev.data) : updater })) };
}
