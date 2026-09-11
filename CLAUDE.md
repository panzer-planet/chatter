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
