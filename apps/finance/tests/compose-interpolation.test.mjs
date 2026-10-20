import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const compose = readFileSync(new URL('../../../docker-compose.finance.yml', import.meta.url), 'utf8');

test('Finance DB bootstrap command is parseable by Docker Compose v1 and v2', () => {
  // Compose interpolates every unescaped dollar in service commands before
  // passing the script to /bin/sh. In production docker-compose v1 rejects $(...).
  assert.match(compose, /\$\$\(printenv FINANCE_DB_PASSWORD \| wc -c\)/);
  assert.doesNotMatch(compose, /(?<!\$)\$\(printenv/, 'unescaped shell substitution blocks deployment');
  assert.match(compose, /FINANCE_DB_PASSWORD must be a strong server-generated secret/);
  assert.match(compose, /finance_user="\$\$\{FINANCE_DB_USER\}"/);
  assert.match(compose, /finance_password="\$\$\{FINANCE_DB_PASSWORD\}"/);
});
