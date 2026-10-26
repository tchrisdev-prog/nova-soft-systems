// Validaciones del lado del cliente: mejoran la experiencia, pero el
// servidor vuelve a validar todo (Form Requests de Laravel).

const ROLE_NAME = /^[\p{L}\p{N}][\p{L}\p{N}\s\-_.()&]*$/u;
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export function validateRole({ name = '', description = '' }) {
  const errors = {};
  const trimmed = name.trim();
  if (!trimmed) errors.name = 'El nombre del rol es obligatorio.';
  else if (trimmed.length < 3) errors.name = 'El nombre debe tener al menos 3 caracteres.';
  else if (trimmed.length > 80) errors.name = 'El nombre no puede superar 80 caracteres.';
  else if (!ROLE_NAME.test(trimmed)) errors.name = 'Usa solo letras, números, espacios y los símbolos - _ . ( ) &.';
  if (description && description.length > 500) errors.description = 'La descripción no puede superar 500 caracteres.';
  return errors;
}

export function validateUser({ name = '', email = '' }) {
  const errors = {};
  if (name.trim().length < 3) errors.name = 'Escribe el nombre completo (mínimo 3 caracteres).';
  if (!email.trim()) errors.email = 'El correo corporativo es obligatorio.';
  else if (!EMAIL.test(email.trim())) errors.email = 'Ingresa un correo válido, por ejemplo nombre@empresa.com.';
  return errors;
}

const PHONE = /^[0-9+()\s-]{7,30}$/;
const NIT_WEIGHTS = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];

/** Dígito de verificación del NIT (método de la DIAN); el servidor lo vuelve a validar. */
export function nitCheckDigit(number) {
  const sum = [...number].reverse().reduce((acc, digit, i) => acc + Number(digit) * NIT_WEIGHTS[i], 0);
  const remainder = sum % 11;
  return remainder > 1 ? 11 - remainder : remainder;
}

export function validateNit(value) {
  const clean = value.replace(/[\s.]/g, '');
  if (!clean) return null;
  const match = /^(\d{6,15})-(\d)$/.exec(clean);
  if (!match) return 'Escribe el NIT con su dígito de verificación, por ejemplo 900123456-8.';
  if (nitCheckDigit(match[1]) !== Number(match[2])) return 'El dígito de verificación no corresponde al NIT. Revisa el número.';
  return null;
}

export function validateClient({ name = '', sector = '', site_id = '', tax_id = '' }) {
  const errors = {};
  if (name.trim().length < 2) errors.name = 'Escribe el nombre del cliente.';
  if (!sector) errors.sector = 'Elige el sector.';
  if (!site_id) errors.site_id = 'Elige la sede que atiende al cliente.';
  const nit = validateNit(tax_id);
  if (nit) errors.tax_id = nit;
  return errors;
}

export function validateContact({ type = '', name = '', email = '', phone = '' }) {
  const errors = {};
  if (!type) errors.type = 'Elige el tipo de contacto.';
  if (name.trim().length < 2) errors.name = 'Escribe el nombre del contacto.';
  if (email.trim() && !EMAIL.test(email.trim())) errors.email = 'Ingresa un correo válido.';
  if (phone.trim() && !PHONE.test(phone.trim())) errors.phone = 'Usa solo números, espacios y + ( ) -.';
  return errors;
}
