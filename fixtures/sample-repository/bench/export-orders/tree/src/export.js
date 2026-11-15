import { execSync } from 'node:child_process';

export function archiveOrders(name) {
  execSync(`tar -czf ${name}.tgz orders/`);
}
