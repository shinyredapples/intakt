import { execSync } from 'node:child_process';

export function orderLog(customerName) {
  return execSync('grep ' + customerName + ' orders.log').toString();
}
