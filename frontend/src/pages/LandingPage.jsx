import { CallToAction } from '../components/landing/CallToAction';
import { Features } from '../components/landing/Features';
import { Footer } from '../components/landing/Footer';
import { Hero } from '../components/landing/Hero';
import { ModuleRoadmap } from '../components/landing/ModuleRoadmap';
import { Navbar } from '../components/landing/Navbar';
import { Overview } from '../components/landing/Overview';
import { SecuritySection } from '../components/landing/SecuritySection';
import { useDocumentTitle } from '../hooks/useDocumentTitle';

export default function LandingPage() {
  useDocumentTitle(null);

  return (
    <>
      <a href="#contenido" className="skip-link">
        Saltar al contenido
      </a>
      <Navbar />
      <main id="contenido">
        <Hero />
        <Overview />
        <Features />
        <ModuleRoadmap />
        <SecuritySection />
        <CallToAction />
      </main>
      <Footer />
    </>
  );
}
