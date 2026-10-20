#!/usr/bin/env python3
"""Run local structural and scenario smoke tests; no real participant or WCAG certification."""
from pathlib import Path
import subprocess
import sys
import json
import tempfile

root = Path(__file__).resolve().parents[1]

def run(*a):
    c = subprocess.run([sys.executable,*map(str,a)], cwd=root, capture_output=True,text=True)
    print(c.stdout.strip())
    if c.returncode:
        print(c.stderr.strip())
        raise RuntimeError('Test failed: '+str(a))

run(root/'scripts/validate_package.py')
run(root/'scripts/inspect_fixtures.py')
for p in sorted((root/'tests/results').glob('audit-*.json')):
    run(root/'scripts/audit_gate.py',p)
for p in sorted((root/'assets/examples').glob('*.md')):
    t=p.read_text(encoding='utf-8')
    assert 'Evidence:' in t or 'Evidence' in t or '**Evidence:**' in t
    assert 'Verify:' in t or 'Verify' in t
    assert 'fixture' in t.lower() or 'макет' in t.lower()
# Negative contract test: the validator must reject absence of evidence and handoff.
with tempfile.NamedTemporaryFile('w',suffix='.json',encoding='utf-8',delete=False) as f:
    json.dump({'context':{},'findings':[{'priority':'P0'}]},f)
    bad=Path(f.name)
result=subprocess.run([sys.executable,str(root/'scripts/audit_gate.py'),str(bad)],capture_output=True,text=True)
bad.unlink()
assert result.returncode == 1, 'validator should reject incomplete/unsupported audit'
print('PASS — rejected incomplete audit as expected')
print('PASS — all synthetic examples and package tests')
