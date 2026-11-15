const OFF = { SAVE5: 5 };

export function applyCoupon(amount, code) {
  const off = OFF[code] ?? 0;

  return amount - off;
}
