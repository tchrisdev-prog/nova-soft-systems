import { useEffect } from 'react';
import { useLocation } from 'react-router';

/** Al cambiar de página se vuelve al inicio (salvo anclas internas). */
export function ScrollToTop() {
  const { pathname, hash } = useLocation();

  useEffect(() => {
    if (!hash) window.scrollTo(0, 0);
  }, [pathname, hash]);

  return null;
}
