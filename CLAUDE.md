# Working on chatter

This repo is the tool you are talking through. Keep it working while you change it.

- `chatter` is one PHP file, `chatter-agent` one bash script (bash 3.2, the macOS default: no `${a/${b}/}`
  nesting, no `BASHPID`, `read -t` returns 1 on both timeout and EOF). `roles/*.md` are the worker prompts.
- The live tool is `/usr/local/bin/chatter`, a symlink to the main checkout, not to your worktree. Your
  edits only go live when merged to master. Never edit files outside your worktree.
- Never touch `~/.chatter/chatter.db`. Test against a scratch database: `CHATTER_DB=/tmp/x.db ./chatter ...`.
  Test the bash logic with stand-ins (sleep, fake tails, fifos); never launch `chatter-agent` or `claude`
  yourself.
- Before every commit: `php -l chatter` and `bash -n chatter-agent`. Every behaviour change updates
  README.md, and PROTOCOL.md if it changes what workers should do. `chatter --help` must stay current.
- Ponytail applies: smallest working change, no speculative options, stdlib first.
- The target is macOS. Linux is not a requirement, but do not make porting hard: when a BSD and a GNU
  tool differ (`stat -f` vs `stat -c`, `sed -i ''`, `date -v`), use the portable form or add the GNU
  fallback on the same line, and prefer what both ship (`pgrep`, `mkfifo`, `uuidgen` with a `/proc`
  fallback). Never reach for anything macOS-only (`osascript`, `launchctl`, `pbcopy`) without a guard.
- Portability review 2026-09-11: every external call in both scripts was checked against BSD and GNU
  userland. Known splits and their handling: `stat -f`/`stat -c` (both tried), `uuidgen`/`/proc` (both
  tried), `/etc/localtime` symlink vs `/etc/timezone` (both read), `git rev-parse --path-format` needs
  git 2.31 (plain form with realpath as fallback). Everything else used is common to both.
