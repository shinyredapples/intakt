import assert from 'node:assert/strict';
import { test } from 'node:test';
import { total } from '../src/cart.js';
import { discounted } from '../src/discount.js';

test('total adds price times quantity', () => {
  assert.equal(total([{ price: 5, quantity: 2 }, { price: 1, quantity: 3 }]), 13);
});

test('a cart of exactly 100 gets the discount', () => {
  assert.equal(discounted(100), 90);
});

test('a small cart gets no discount', () => {
  assert.equal(discounted(50), 50);
});
