import { Button } from '../ui/Button';
import { ModuleBoard } from './ModuleBoard';
import styles from './Hero.module.scss';

export function Hero() {
  return (
    <section className={styles.hero} aria-labelledby="hero-title">
      <div className={styles.inner}>
        <div className={styles.copy}>
          <h1 id="hero-title" className={styles.title}>
            La operación de tu empresa, ordenada por módulos.
          </h1>
          <p className={styles.lead}>
            Nova Sync Systems es un ERP que reúne los procesos de la empresa en una sola plataforma. Cada área trabaja en su
            módulo y cada persona ve solo lo que su rol le permite.
          </p>
          <div className={styles.actions}>
            <Button to="/login" size="lg" icon="microsoft">
              Ingresar con Microsoft 365
            </Button>
            <Button href="#modulos" size="lg" variant="secondary">
              Ver los módulos
            </Button>
          </div>
          <p className={styles.note}>Acceso exclusivo para cuentas corporativas autorizadas por tu organización.</p>
        </div>

        <div className={styles.visual}>
          <ModuleBoard />
        </div>
      </div>
    </section>
  );
}
