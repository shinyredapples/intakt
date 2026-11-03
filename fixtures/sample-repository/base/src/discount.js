// A cart of 100 or more gets 10 percent off.
export function discounted(amount) {
  if (amount >= 100) {
    return amount * 0.9;
  }

  return amount;
}
