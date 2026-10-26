import { Button } from '../components/ui/Button';
import { EmptyState } from '../components/ui/StateMessage';
import { useDocumentTitle } from '../hooks/useDocumentTitle';

export default function ForbiddenPage() {
  useDocumentTitle('Acceso restringido');
  return (
    <EmptyState
      icon="lock"
      title="No tienes acceso a esta sección"
      message="Tus roles actuales no incluyen permisos para este módulo. Si lo necesitas para tu trabajo, solicítalo al administrador del sistema."
      action={
        <Button to="/app" variant="secondary" icon="home">
          Ir al panel
        </Button>
      }
    />
  );
}
