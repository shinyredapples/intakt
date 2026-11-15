export function total(items) {
  const sum = items.reduce((acc, item) => acc + item.price * item.quantity, 0);

  return Math.round(sum * 100) / 100;
}
