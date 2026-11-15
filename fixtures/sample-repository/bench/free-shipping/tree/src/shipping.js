// Orders of 50 or more ship free.
export function shippingCost(orderTotal) {
  if (orderTotal > 50) {
    return 0;
  }

  return 5;
}
