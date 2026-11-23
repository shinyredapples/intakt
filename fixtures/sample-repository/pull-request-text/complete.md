fix(discount): give a cart of exactly 100 the discount

## Summary
- The discount now starts at 100 instead of above it.

## Evidence
- E1: `npm test` passes, with the case for exactly 100.
- E2: a cart of 100 costs 90, a cart of 99 costs 99.
- E3: not satisfied, because the owner proves it.
