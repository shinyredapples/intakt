Give a cart of exactly 100 the discount.

The discount in `src/discount.js` starts above 100, so a cart of exactly 100 loses it.

## Acceptance
- `discount(100)` returns the discounted amount.

## Evidence
- E1: the test run with the case for exactly 100
- E2: the discount of a cart of 100 and of 99
  - both printed by the test run
- E3: the README line about the discount (owner provides)
