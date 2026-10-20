#!/usr/bin/env python3
"""Read-only, bounded repository inventory. Never prints secrets or .env contents."""
import argparse
import json
import pathlib
import subprocess
import sys

NAMES=('AGENTS.md','CLAUDE.md','README.md','composer.json','composer.lock','phpunit.xml','pest.php','Dockerfile','compose.yaml','docker-compose.yml','phpstan.neon','phpstan.neon.dist','pint.json')
FOLDERS=('routes','app','tests','database/migrations','config','.github/workflows')


def inspect(root):
    files=[]
    for name in NAMES:
        p=root/name
        if p.is_file(): files.append(name)
    folder_counts={}
    for name in FOLDERS:
        p=root/name
        if p.is_dir():
            # bounded, paths only; don't recurse arbitrary private directories
            folder_counts[name]=sum(1 for f in p.rglob('*') if f.is_file() and len(f.relative_to(p).parts)<=5 and f.suffix in ('.php','.yml','.yaml','.md'))
    out={'root':str(root),'known_files':files,'folder_source_counts':folder_counts}
    manifest=root/'composer.json'
    if manifest.is_file():
        try:
            data=json.loads(manifest.read_text('utf8'))
            allowed=('php','laravel/framework','phpunit/phpunit','pestphp/pest','laravel/horizon','predis/predis')
            out['composer_constraints']={k:v for section in ('require','require-dev') for k,v in data.get(section,{}).items() if k in allowed and isinstance(v,str)}
            out['composer_scripts_available']=sorted(data.get('scripts',{}))
        except (OSError,ValueError,TypeError) as e:
            out['composer_error']=type(e).__name__
    try:
        p=subprocess.run(['git','status','--short','--branch'],cwd=root,capture_output=True,text=True,timeout=5,check=False)
        if p.returncode==0:
            # only branch and count: tracked modifications might contain confidential names
            out['git_branch_info']=p.stdout.splitlines()[0] if p.stdout else ''
            out['git_pending_entries']=max(0,len(p.stdout.splitlines())-1)
    except (OSError,subprocess.TimeoutExpired):
        pass
    return out

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('path',nargs='?',default='.')
    args=parser.parse_args()
    root=pathlib.Path(args.path).resolve()
    if not root.is_dir():print('Not a directory',file=sys.stderr);sys.exit(2)
    print(json.dumps(inspect(root),ensure_ascii=False,indent=2))
