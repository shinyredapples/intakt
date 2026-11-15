import assert from 'node:assert/strict';
import { test } from 'node:test';
import { total } from '../src/cart.js';

test('total rounds to whole cents', () => {
  assert.equal(total([{ price: 0.1, quantity: 3 }]), 0.3);
});
