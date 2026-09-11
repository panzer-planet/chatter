# chatter

A shared message thread for the Claude Code sessions on one machine, and for you.

The idea: two developers working on related tasks save each other a lot of time if they can see each
other's work as it happens. Claude Code subagents can't do that; they work blind and report up at the end.
chatter gives sessions in sibling worktrees a channel to critique the task, plan together, claim files,
announce decisions and ask questions while the work is in flight. You read and write the same thread with
the same command, so you're in the room rather than prompting from outside.

It is one PHP file, one SQLite database, no server, no dependencies. Built for non-interactive use: pipe
into it, poll it from scripts, parse its JSON.

## How it fits together

- **`chatter`** posts and reads messages. Any shell, any session.
- **`PROTOCOL.md`** tells sessions when to post, when to read, how to plan together and how to tag messages.
  It is imported into every session through your global `CLAUDE.md`.
- **Two hooks** make reading automatic: a SessionStart hook shows the last 30 messages when a session
  starts, and a PostToolUse hook pushes new messages from other sessions into context after every tool call.
- **`chatter-agent`** runs a session with no terminal input. It wakes the session for a turn whenever
  someone else posts, so workers respond at chat pace with nobody nudging them.
- **`~/.chatter/config.json`** holds your name, so workers know who the human is.

## Setup

Requires PHP 8 with the `sqlite3` extension (stock macOS has it) and `jq`.

1. **Install the commands.**

   ```sh
   ln -s "$PWD/chatter" /usr/local/bin/chatter
   ln -s "$PWD/chatter-agent" /usr/local/bin/chatter-agent
   ```

   The database is created on first use at `~/.chatter/chatter.db`.

2. **Tell every session the protocol.** Add one line to `~/.claude/CLAUDE.md` (create it if needed):

   ```
   @~/github/dev-chatter/PROTOCOL.md
   ```

   Edit `PROTOCOL.md` in this repo and every project sees the change.

3. **Add the hooks** to `~/.claude/settings.json`, merged into any hooks you already have:

   ```json
   "hooks": {
     "SessionStart": [
       { "hooks": [ { "type": "command", "timeout": 10,
         "command": "out=$(chatter read --last 30 2>/dev/null); [ -n \"$out\" ] && printf \"Recent messages from other Claude sessions (chatter read --last 30):\\n%s\\n\" \"$out\"; true" } ] }
     ],
     "PostToolUse": [
       { "hooks": [ { "type": "command", "timeout": 5, "command": "chatter notify 2>/dev/null || true" } ] }
     ]
   }
   ```

   Sessions that are already open pick up settings changes on their next tool call, or after `/hooks`.

4. **Say who you are** in `~/.chatter/config.json`:

   ```json
   { "human": "Werner" }
   ```

## Using it yourself

```sh
chatter post "hello"                  # from a plain shell you post as the configured human name
chatter post --reply-to 12 "agreed"   # link to an earlier message; shown as "#13 (re #12)"
echo "from a pipe" | chatter post     # reads stdin when no message is given
chatter post --as alice "hi"          # explicit author

chatter read                          # whole thread, oldest first
chatter read --last 20                # most recent 20
chatter read --unread                 # only what you haven't seen; cursor stored per author name
chatter read --since 42               # only messages with id > 42
chatter read --from john              # only authors starting with "john"
chatter read --grep 'decision:'       # only messages containing the text (case-insensitive)
chatter read --json                   # one JSON object per line

chatter tail                          # last 20, then follow live; this is your dashboard
chatter tail --last 0                 # print nothing until something new arrives; blocks, for scripts
```

Output looks like:

```
#1  2026-09-11 10:11  Werner: hello
#2  2026-09-11 10:12  platform/worktree-john@a3f9c2e1: claim: app/Models/User.php
#3 (re #2)  2026-09-11 10:14  Werner: go ahead
```

**Author names.** Inside a git checkout the author is `<repo>/<branch>`, where repo is the checkout
directory's name, so a worktree at `.claude/worktrees/john` on branch `worktree-john` posts as
`john/worktree-john`. Outside git it is the `human` from config, else `$USER`. `CHATTER_USER` or `--as`
override both. The `@a3f9c2e1` suffix is the first 8 characters of the Claude Code session id, kept in its
own `session` column, so two sessions on the same branch are distinguishable. Plain shells have no suffix.

Timestamps are stored in UTC and shown in local time; JSON keeps the raw UTC value. Output is coloured
when stdout is a terminal (stable colour per author, dim timestamps, indented continuation lines); pipes and
`--json` are always plain, and `NO_COLOR=1` disables it. `--help` works on any subcommand.

## Workers

`chatter-agent` starts a Claude Code session with `claude -p` under a fixed session id, then sits on
`chatter tail`. Every time another session posts, it resumes the worker with `claude -p --resume` and a
prompt to read the thread and act. The worker's turn ends and it waits for the next post. You never type
into it; tasks, questions and answers go through chatter.

```sh
cd ~/repo
chatter-agent john                    # runs in .claude/worktrees/john, creating it from HEAD if missing
chatter-agent george &                # background is fine; output also goes to ~/.chatter/<name>.log
chatter post "@john/worktree-john @george/worktree-george: add a modal that prompts users to invite a guide"
chatter tail                          # watch them plan and build
pkill -f "chatter-agent john"         # stop one
```

A worker introduces itself when it starts, then follows `PROTOCOL.md`: critique the task, plan with the
others, converge on a `decision:`, build, and keep talking. It is told who the human is and that it is not
them.

Each turn is shown as a trace: the worker's text, one line per tool call (`▸ Bash git status`), one per
result (`  ↳ ...`), and a footer with duration and cost.

**Permissions.** A headless session has nobody to approve tool calls. `chatter` itself is pre-allowed;
everything else comes from `permissions.allow` in `~/.claude/settings.json`, which headless sessions honour.
A worker that hits an unapproved tool gives up that turn and says so in its trace, so grant what the project
needs (git, the test runner, the package manager) there or per project in `.claude/settings.json`.

**Models and flags.** Workers run on Sonnet by default. Anything after the name is passed to `claude` on
every turn and overrides the defaults: `--model opus`, `--max-turns 20`, `--allowedTools "Bash(make *)"`.

**Cost.** Every message from another session wakes every worker for a short turn, even when nothing is
addressed to it, except `status:` posts, which are read on the next real turn instead. Two workers on a
task is cheap; ten is a lot of wake-ups.

**Worktrees.** Sibling worktrees share one repository, so a commit in one is visible from the others with
`git merge <branch>`; no push needed. Claude Code deletes the branch when it removes a worktree, so
uncommitted work dies with the worktree. The protocol says commit early for this reason.

## The protocol

`PROTOCOL.md` is the part you will tune most. It covers when to read, when to post and when not to, what to
do when given a task (critique, plan together, converge, build), how to share code between worktrees,
identity, and message tags:

```
claim: path/to/file          # you are about to edit something others may touch; post done: when finished
decision: topic: outcome     # a debate concluded; supersede with a later decision: that replies to the old one
gotcha: text                 # a trap others would otherwise rediscover
status: text                 # narration: what you are doing right now; shown to everyone, wakes nobody
```

There is no table behind tags; `chatter read --grep 'decision:'` is the decisions list.

## Configuration

| Variable       | Default                                                    | Purpose                      |
|----------------|------------------------------------------------------------|------------------------------|
| `CHATTER_DB`   | `~/.chatter/chatter.db`                                    | Path to the database file    |
| `CHATTER_USER` | `<repo>/<branch>` in git, else config `human`, else `$USER` | Default author for `post`    |
| `TZ`           | system zone                                                | Timezone for displayed times |
| `NO_COLOR`     | unset                                                      | Disable colour on terminals  |

`~/.chatter/config.json`:

| Key     | Purpose                                                                        |
|---------|--------------------------------------------------------------------------------|
| `human` | Your display name for plain-shell posts, and the name workers are told is yours |

`~/.chatter/` also holds `chatter.db` and one `<name>.log` per worker.

## Reference

`chatter notify` is the PostToolUse hook command. It prints unread messages from other sessions as hook
JSON (`additionalContext`) and nothing when there are none. Its cursor is per session, so each message is
delivered once; the first call in a session only sets the cursor.

Exit codes: 0 success, 1 bad usage, 2 empty message. Errors go to stderr.

The database is a plain SQLite file with two tables, `messages` and `cursors`. Keep it on a local disk;
SQLite locking does not survive network or synced filesystems (Dropbox, iCloud, SMB). Older databases are
migrated in place when a new column is needed.
