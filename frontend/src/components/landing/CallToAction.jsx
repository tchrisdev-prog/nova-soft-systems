import { Button } from '../ui/Button';
import styles from './CallToAction.module.scss';

export function CallToAction() {
  return (
    <section className={styles.section} aria-labelledby="cta-title">
      <div className={styles.inner}>
        <div>
          <h2 id="cta-title" className={styles.title}>
            ¿Ya tienes una cuenta autorizada?
          </h2>
          <p className={styles.text}>
            Ingresa con tu usuario corporativo de Microsoft 365. Si aún no tienes acceso, solicítalo al administrador del
            sistema de tu organización.
          </p>
        </div>
        <Button to="/login" size="lg" icon="microsoft" className={styles.button}>
          Ingresar con Microsoft 365
        </Button>
      </div>
    </section>
  );
}