"""Package a committed candidate and an already tested Vite build; never contacts live."""
from pathlib import Path
import argparse
import hashlib
import json
import subprocess
import tarfile

root = Path(__file__).resolve().parents[2]
args = argparse.ArgumentParser()
args.add_argument('--output', required=True)
options = args.parse_args()
out = Path(options.output).resolve()
if out.exists():
    raise SystemExit('Output must be a new directory; an existing candidate is never overwritten.')
def git(*arguments):
    return subprocess.check_output(['git', *arguments], cwd=root).decode().strip()
def allowed(name):
    return not name.startswith(('vendor/', '.vscode/', '.git/', 'local-backups/', 'node_modules/')) and not Path(name).name.startswith('.env')
dirty = git('diff', '--name-only', 'HEAD').splitlines()
untracked = git('ls-files', '--others', '--exclude-standard').splitlines()
if any(allowed(p) for p in dirty + untracked):
    raise SystemExit('Commit all application changes before packaging; local vendor changes are excluded.')
revision = git('rev-parse', 'HEAD')
base = 'e93bc9230ffc7c9a9817319bafa78a46b8925489'
subprocess.run(['git', 'merge-base', '--is-ancestor', base, revision], cwd=root, check=True)
source = [p for p in git('ls-files').splitlines() if allowed(p)]
build = [p.relative_to(root).as_posix() for p in (root/'public/build').rglob('*') if p.is_file()]
manifest = root/'public/build/.vite/manifest.json'
if not manifest.is_file():
    raise SystemExit('Production build is missing.')
for value in json.loads(manifest.read_text()).values():
    for name in [value['file'], *value.get('css', []), *value.get('assets', [])]:
        if not (root/'public/build'/name).is_file():
            raise SystemExit('Build reference missing: '+name)
out.mkdir(parents=True)
hashes = {}
for archive, names in [('source.tar.gz', source), ('build.tar.gz', build)]:
    with tarfile.open(out/archive, 'w:gz') as tar:
        for name in sorted(names):
            p = root/name
            if p.is_symlink() or not p.is_file():
                raise SystemExit('Unexpected source type: '+name)
            hashes[name] = hashlib.sha256(p.read_bytes()).hexdigest()
            info = tar.gettarinfo(str(p), name)
            info.uid = info.gid = 0
            info.uname = info.gname = ''
            info.mode = 0o644
            with p.open('rb') as content:
                tar.addfile(info, content)
    # Verify the actual archive bytes against the files used for this candidate.
    with tarfile.open(out/archive) as tar:
        for entry in tar:
            assert entry.isfile() and not entry.name.startswith('/') and '..' not in Path(entry.name).parts
            assert hashlib.sha256(tar.extractfile(entry).read()).hexdigest() == hashes[entry.name]
(out/'FILES.sha256').write_text(''.join(f'{sha}  {name}\n' for name,sha in sorted(hashes.items())), encoding='utf8')
(out/'changed-files.txt').write_text(git('diff', '--name-status', base, revision)+'\n', encoding='utf8')
(out/'candidate.json').write_text(json.dumps(dict(revision=revision, base=base, branch=git('branch','--show-current'),
    sourceFiles=len(source), buildFiles=len(build), dependencies='composer install --no-dev from unchanged composer.lock',
    excludes=['.env', 'vendor', 'database contents', 'test output', 'node_modules'],
    deploymentStatus='PREPARED_ONLY'), indent=2)+'\n', encoding='utf8')
(out/'RELEASE.md').write_bytes((root/'docs/four-modules-release.md').read_bytes())
checksums = {p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in out.iterdir() if p.is_file()}
(out/'SHA256SUMS').write_text(''.join(f'{sha}  {name}\n' for name,sha in sorted(checksums.items())), encoding='utf8')
print(json.dumps(dict(output=str(out), revision=revision, files=len(hashes), archivesVerified=True)))
