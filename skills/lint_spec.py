#!/usr/bin/env python3
"""Heuristic quality lint for analyst Markdown specifications; no semantic validation."""
import re
import sys
from pathlib import Path

REQUIRED = ["Goal and evidence", "Scope and glossary", "Actors and access", "Requirements", "Acceptance and verification", "Change and impact analysis", "Unknowns, decisions and readiness"]
ID = re.compile(r'\b(?:BR|FR|NFR|INT|DATA|AUTH|TR|AC|RISK|Q|DEC)-\d{2,}\b')
VAGUE = re.compile(r'\b(?:fast|easy|user.friendly|quickly|as needed|convenient|быстро|удобно|корректно)\b', re.I)

def lint(p):
    t = Path(p).read_text(encoding='utf8')
    errors=[]
    for h in REQUIRED:
        if h.lower() not in t.lower(): errors.append('MISSING SECTION: '+h)
    ids=ID.findall(t)
    if not ids: errors.append('NO TRACE IDS')
    if not re.search(r'\bGiven\b|\bДано\b',t,re.I) or not re.search(r'\bWhen\b|\bКогда\b',t,re.I) or not re.search(r'\bThen\b|\bТогда\b',t,re.I): errors.append('NO OBSERVABLE GWT CRITERIA')
    for m in VAGUE.finditer(t): errors.append('CHECK VAGUENESS: '+repr(m.group(0)))
    if 'BLOCKED' not in t and 'READY' not in t and 'CONDITIONAL' not in t: errors.append('NO READINESS STATUS')
    return errors

if __name__=='__main__':
    if len(sys.argv)!=2: raise SystemExit('usage: lint_spec.py PATH.md')
    issues=lint(sys.argv[1]); print('\n'.join(issues) if issues else 'PASS (heuristic only)'); sys.exit(bool(issues))
