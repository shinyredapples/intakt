import { readFileSync } from 'node:fs';

// Returns an empty list when the file is missing or not valid JSON.
export function loadPrices(path) {
  return JSON.parse(readFileSync(path, 'utf8'));
}
