#!/usr/bin/env python3
"""Offline checks for core risk controls. Tests presence and fixture results, NOT a real Laravel integration."""
from pathlib import Path
import subprocess
import sys

root=Path(__file__).resolve().parents[1]
checks={
    'API auth and validation': ['references/api-contracts.md','references/security-identity.md','examples/api/document_api.php'],
    'Messaging reliability': ['references/messaging.md','examples/messaging/message_consumer.php'],
    'Concurrency/DB performance': ['references/postgresql.md','examples/concurrency/atomic_stock.php'],
}
fail=0
for title,paths in checks.items():
    missing=[p for p in paths if not (root/p).is_file()]
    if missing: print('FAIL',title,missing);fail+=1;continue
    target=root/paths[-1]
    lint=subprocess.run(['php','-l',str(target)],capture_output=True,text=True)
    run=subprocess.run(['php',str(target)],capture_output=True,text=True) if lint.returncode==0 else None
    passed=lint.returncode==0 and run.returncode==0 and 'ALL CHECKS PASSED' in run.stdout
    print(('PASS' if passed else 'FAIL'),title)
    if not passed:
        fail+=1
        print('lint:',lint.stdout,lint.stderr)
        if run:print('run:',run.stdout,run.stderr)
print('NOTE: These are lightweight PHP behavioral fixtures, not Laravel/PostgreSQL/RabbitMQ integration tests.')
sys.exit(1 if fail else 0)
