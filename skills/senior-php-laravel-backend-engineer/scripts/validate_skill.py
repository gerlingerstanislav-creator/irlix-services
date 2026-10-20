#!/usr/bin/env python3
"""Read-only offline validation of the Agent Skills bundle (standard library only)."""
import argparse
import pathlib
import re
import sys

NAME = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$")
LINK = re.compile(r"\]\((?!https?://|#|mailto:)([^)]+)\)")


def validate(root: pathlib.Path):
    problems=[]
    manifest=root/'SKILL.md'
    if not manifest.is_file():
        return ['SKILL.md missing']
    body=manifest.read_text(encoding='utf8')
    if not body.startswith('---\n'):
        return ['SKILL.md: YAML frontmatter missing']
    parts=body.split('---\n',2)
    if len(parts) < 3:
        return ['SKILL.md: YAML frontmatter not closed']
    header=parts[1]
    # No third-party YAML runtime required; support simple unquoted and quoted top-level fields.
    fields={}
    for line in header.splitlines():
        if line.startswith(' ') or ':' not in line: continue
        k,v=line.split(':',1)
        fields[k.strip()]=v.strip().strip('"\'')
    name=fields.get('name','')
    desc=fields.get('description','')
    if not NAME.fullmatch(name) or len(name)>64 or name!=root.name:
        problems.append(f'invalid skill name or root folder: {name!r}')
    if not (1<=len(desc)<=1024):
        problems.append(f'invalid description length {len(desc)}')
    if len(body.splitlines())>500: problems.append('SKILL.md exceeds 500 lines')
    for path in sorted(root.rglob('*')):
        if not path.is_file():continue
        if path.is_symlink(): problems.append(f'symlink not allowed: {path}')
        if path.suffix.lower() not in ('.md','.py','.php','.sql','.txt','.json','.yaml','.yml'):
            problems.append(f'unexpected extension: {path.relative_to(root)}')
        if path.suffix.lower()=='.md':
            try: data=path.read_text(encoding='utf8')
            except UnicodeError:
                problems.append(f'bad utf8: {path}');continue
            for link in LINK.findall(data):
                destination=(path.parent/link.split('#',1)[0]).resolve()
                if not destination.is_relative_to(root.resolve()) or not destination.exists():
                    problems.append(f'broken/escaping link in {path.relative_to(root)}: {link}')
    return problems


def main():
    p=argparse.ArgumentParser()
    p.add_argument('root',nargs='?',default=str(pathlib.Path(__file__).resolve().parents[1]))
    args=p.parse_args()
    root=pathlib.Path(args.root).resolve()
    issues=validate(root)
    for issue in issues: print('ERROR:',issue)
    if issues: print(f'FAIL: {len(issues)} problems');return 1
    print('PASS: skill metadata, structure and local links valid')
    return 0

if __name__=='__main__': sys.exit(main())
