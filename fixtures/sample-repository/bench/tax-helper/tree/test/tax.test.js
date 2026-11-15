import assert from 'node:assert/strict';
import { test } from 'node:test';
import { withTax } from '../src/tax.js';

test('withTax adds the rate to the amount', () => {
  assert.equal(withTax(100, 0.25), 125);
});
