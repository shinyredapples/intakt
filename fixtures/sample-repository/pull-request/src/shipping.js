export function shippingCost(weightInKilograms) {
  if (weightInKilograms < 1) {
    return 3;
  }

  return 3 + Math.ceil(weightInKilograms - 1) * 2;
}
