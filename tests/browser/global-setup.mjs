// Every run starts from a freshly installed Craft: the tests create, switch and delete presets.
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

export default function globalSetup() {
    execFileSync(fileURLToPath(new URL('install.sh', import.meta.url)), { stdio: ['ignore', 'ignore', 'inherit'] });
}
