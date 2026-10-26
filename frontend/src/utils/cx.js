/** Une nombres de clase ignorando valores falsos: cx('a', cond && 'b'). */
export function cx(...values) {
  return values.filter(Boolean).join(' ');
}
