#!/usr/bin/env python3
"""Check installable skill structure and local reference links."""
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
skill = ROOT / 'SKILL.md'
errors=[]
if not skill.exists(): errors.append('SKILL.md missing')
else:
    text=skill.read_text(encoding='utf-8')
    if not text.startswith('---\n') or '\n---\n' not in text[4:]: errors.append('frontmatter missing')
    if not re.search(r'^name:\s*senior-product-uiux-designer\s*$',text,re.M): errors.append('invalid skill name')
    if not re.search(r'^description:\s*\S',text,re.M): errors.append('description missing')
    for f in sorted(set(re.findall(r'`((?:references|assets|scripts)/[^`]+\.(?:md|py))`',text))):
        if '*' not in f and not (ROOT/f).exists(): errors.append('broken reference: '+f)
for p in ROOT.rglob('*'):
    if p.is_file() and p.suffix=='.md':
        try: p.read_text(encoding='utf-8')
        except UnicodeDecodeError: errors.append('encoding issue: '+str(p))
    if p.is_symlink(): errors.append('symlink not allowed: '+str(p))
if errors:
    print('FAIL:', *errors,sep='\n - ')
    sys.exit(1)
print('PASS: frontmatter, references, UTF-8, no symlinks')
