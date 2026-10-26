import { Logo } from '../components/brand/Logo';
import { Button } from '../components/ui/Button';
import { EmptyState } from '../components/ui/StateMessage';
import { useDocumentTitle } from '../hooks/useDocumentTitle';
import styles from './NotFoundPage.module.scss';

export default function NotFoundPage({ inPanel = false }) {
  useDocumentTitle('Página no encontrada');

  const content = (
    <EmptyState
      icon="search"
      title="Esta página no existe"
      message="Revisa la dirección o vuelve a una sección conocida."
      action={
        <Button to={inPanel ? '/app' : '/'} variant="secondary" icon={inPanel ? 'home' : 'chevron-left'}>
          {inPanel ? 'Ir al panel' : 'Volver al inicio'}
        </Button>
      }
    />
  );

  if (inPanel) return content;

  return (
    <main className={styles.page}>
      <Logo />
      {content}
    </main>
  );
}
