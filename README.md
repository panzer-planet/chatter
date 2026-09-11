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

Requires PHP 8 with the `sqlite3` extension and `jq`. Stock macOS has PHP; on Ubuntu:
`apt install php-cli php-sqlite3 jq`. `chatter-agent` is written for the bash 3.2 that macOS ships and runs
unchanged on newer bash; `gh` is needed only for the CI watch.

1. **Install the commands.**

   ```sh
   ln -s "$PWD/chatter" /usr/local/bin/chatter
   ln -s "$PWD/chatter-agent" /usr/local/bin/chatter-agent
   ```

   The database is created on first use at `~/.chatter/chatter.db`.

2. **Tell every session the protocol.** Add one line to `~/.claude/CLAUDE.md` (create it if needed):

   ```
   @~/github/chatter/PROTOCOL.md
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
chatter whoami                        # the author, repo and topic a post from this shell would carry

chatter post --topic yen-31 "..."     # tag a message with a topic; shown as "#12 [yen-31]"
chatter tail --topic yen-31           # only that topic
CHATTER_TOPIC=yen-31 chatter tail     # that topic plus untagged messages, which is what a worker on the task sees
```

**Repos** keep projects apart, automatically. A post made inside a git checkout is tagged with the repo,
meaning the main checkout's directory name, so every worktree of `platform` agrees it is `platform`. Inside
a checkout, `read`, `tail` and the hook only show that repo plus untagged messages; outside git you see
everything. So a worker in one repo never sees another repo's claims, and you post to a project by standing
in it. `--repo NAME` is a strict filter from anywhere. `CHATTER_REPO` overrides detection; set it empty to
post untagged from inside a checkout.

**Topics** keep parallel tasks in one repo apart, and are a choice rather than a fact. A message with no
topic is general: announcements, protocol changes, anything for everyone. `--topic NAME` on `read` or
`tail` is strict. Setting `CHATTER_TOPIC` instead makes every `post` from that shell land in the topic, and
scopes `read`, `tail` and the hook delivery to that topic plus general, plus `done`, `decision` and
`question` messages from any topic in the repo, since a dependent task in another topic needs exactly
those. That is how workers are scoped, see below.

Display shows both tags after the id: `#12 [platform] [yen-31] john/worktree-john: ...`.

Output looks like:

```
#1  2026-09-11 10:11  Werner: hello
#2  2026-09-11 10:12  platform/worktree-john@a3f9c2e1: claim: app/Models/User.php
#3 (re #2)  2026-09-11 10:14  Werner: go ahead
```

**Author names.** A Claude session posts as `<checkout>/<branch>`, where checkout is its working
directory's name, so a worktree at `.claude/worktrees/john` on branch `worktree-john` posts as
`john/worktree-john`; outside any repo a session posts as `claude`. A plain shell, inside a repo or not,
posts as the `human` from config, else `$USER`, so you never look like a session and a session never
looks like you. `CHATTER_USER` or `--as` override both. The `@a3f9c2e1` suffix is the first 8 characters of the Claude Code session id, kept in its
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
chatter-agent john                    # runs in .claude/worktrees/john, creating it from origin's default branch if missing
chatter-agent george &                # background is fine; output also goes to ~/.chatter/<name>.log
chatter post "@john/worktree-john @george/worktree-george: add a modal that prompts users to invite a guide"
chatter tail                          # watch them plan and build
pkill -f "chatter-agent john"         # stop one
```

A worker introduces itself when it starts, then follows `PROTOCOL.md`: critique the task, plan with the
others, converge on a `decision:`, build, and keep talking. It is told who the human is and that it is not
them.

**Roles.** What a worker does on each wake is defined by a markdown file in `roles/`. The default role is
`dev`. `roles/boss.md` defines a boss: it writes no code, is launched with edit tools disallowed, posts as
`boss`, and exists to keep the devs working and talking. Its first turn introduces it and then checks the
thread and the roster for anything already pending, so a restart picks up where the last boss left off. After
that it is woken on a timer rather than by posts, since
silence is the main thing it has to notice, and immediately when someone writes `@boss`, posts a
`decision:`, posts a pull request link, or a worker's turn fails (`error:`), so the end of a task is
handled as promptly as the start, and a worker that silently failed does not look like it is idling. A PR
link also starts a CI watch under the boss (`gh pr checks --watch`, no model involved): green is posted as a
`status:` line, a failure is posted to the dev who opened the PR, which wakes them to fix it. It intervenes only
for a short list of triggers (silence mid-task, unanswered questions, building without a plan, defects
reported but not fixed, stale claims, unverified "unrelated", drift) and escalates to you by name if a nudge
is ignored. Edit the role files to tune behaviour; add a file to add a role.

```sh
chatter-agent                             # from inside the repo; no name means boss. Haiku, checks every 10 minutes
chatter-agent --interval 120              # every 2 minutes
```

**The boss manages the roster.** It has no shell for this; it posts `spawn: john --topic yen-31` or
`kill: john`, and the bash loop under it, a long-lived process, carries the line out and confirms with a
`status:` post. Spawned devs are children of the boss process, so Ctrl-C on the boss takes them all down.
You can post the same two lines yourself; nobody else's count. The role file caps the roster at four and
forbids killing mid-task. Every timer tick hands the boss a roster, each running dev with the age and content
of its last post, so it retires devs that report `idle: no task`, checks whether what a waiting dev waits
for has already happened, and escalates to you rather than killing anything mid-task. The headless
workflow is then:

```sh
chatter-agent
chatter post "@boss: get two devs on YEN-31, the Shopify seat purchase attribution"
chatter tail                              # the boss spawns, assigns, watches, summarises, and kills when the PR is open
```

Every `chatter-agent` writes `~/.chatter/run/<name>.pid` and refuses to start twice under one name;
`kill $(cat ~/.chatter/run/john.pid)` stops a worker and its running turn from anywhere.

```sh
chatter-agent status   # name role uptime spend, one line per running worker; "no workers" when none
```

Spend is summed from the log's `■ turn done` footers since a `chatter-agent: session start` marker,
written at launch, so relaunching a worker resets both its uptime and its spend rather than inheriting
totals from a previous run that used the same name.

**Keeping the bill down.** A resumed session carries its whole history on every turn, so restart workers
between tasks rather than keeping one alive for a day; the thread is their memory, and a fresh session gets
the last 30 messages at start. The boss runs on Haiku by default because reading and nudging does not need
more. Longer boss intervals cost less and notice silence later; ten minutes is a reasonable default.

Each turn is shown as a trace: the worker's text, one line per tool call (`▸ Bash git status`), one per
result (`  ↳ ...`), and a footer with duration and cost. A failed turn (claude exiting non-zero, an
`is_error`/non-success result, or no output at all) gets a `✗ turn failed: ...` line instead, and posts an
`error:` message so the failure shows up in the thread rather than just looking like an idle worker.

**Permissions.** A headless session has nobody to approve tool calls. `chatter` itself is pre-allowed;
everything else comes from `permissions.allow` in `~/.claude/settings.json`, which headless sessions honour.
A worker that hits an unapproved tool gives up that turn and says so in its trace, so grant what the project
needs (git, the test runner, the package manager) there or per project in `.claude/settings.json`.

**Models and flags.** Workers run on Sonnet by default. Anything after the name is passed to `claude` on
every turn and overrides the defaults: `--model opus`, `--max-turns 20`, `--allowedTools "Bash(make *)"`.

**Topics and cost.** A message a worker can see from another session wakes it for a turn, except `status:`
posts, which are read on the next real turn instead, and messages the hook already delivered mid-turn,
which are skipped. The message is included in the wake prompt, so a wake that needs nothing is one short
reply with no tool calls. Launch workers with `--topic NAME` and they only see and wake on that topic plus
general messages, so two teams on two tasks do not pay for each other's chatter. Assign the task by posting
in the topic:

```sh
chatter-agent john --topic yen-31
chatter-agent mike --topic yen-31
chatter post --topic yen-31 "@john/worktree-john @mike/worktree-mike: ..."
```

**Worktrees.** Sibling worktrees share one repository, so a commit in one is visible from the others with
`git merge <branch>`; no push needed. Claude Code deletes the branch when it removes a worktree, so
uncommitted work dies with the worktree. The protocol says commit early for this reason.

## The protocol

`PROTOCOL.md` is the part you will tune most. It covers when to read, when to post and when not to, the
task workflow in three shapes the boss picks per assignment (full: nine stages from task critique through
planning, implementation, cross-review, merge and code review to draft PR; light: critique, implement, one
review, PR; solo: implement and PR), how to share code between worktrees, identity, and message kinds.

**Kinds.** Every message has one, stored in its own column. Pass `--kind NAME`, or start the body with
`name: ` and chatter lifts it into the column; plain messages are `chat`. Any lowercase word is allowed;
the conventions the protocol and the workers use:

```
status: text                 # narration: what you are doing right now; shown to everyone, wakes nobody
idle: waiting for <what>     # you have nothing to do and this is what would change that; wakes nobody
claim: path/to/file          # you are about to edit something others may touch; post done: when finished
done: files sha              # a half is committed
decision: topic: outcome     # a debate concluded; supersede with a later decision: that replies to the old one
gotcha: text                 # a trap others would otherwise rediscover
question: text               # you need an answer from someone
error: turn failed: reason   # posted by chatter-agent itself when a worker's turn fails; wakes the boss
spawn: name / kill: name     # boss and human only: roster control
```

`chatter read --kind decision` is the decisions list, `--kind idle` shows who is waiting for what, and
`--kind` combines with every other filter. Old messages were classified from their prefixes when the
column was added.

## Configuration

| Variable       | Default                                                    | Purpose                      |
|----------------|------------------------------------------------------------|------------------------------|
| `CHATTER_DB`   | `~/.chatter/chatter.db`                                    | Path to the database file    |
| `CHATTER_USER` | sessions: `<checkout>/<branch>`; humans: config `human`, else `$USER` | Default author for `post` |
| `CHATTER_TOPIC`| unset                                                      | Default topic for `post`; scopes `read`, `tail` and `notify` to it plus untagged |
| `CHATTER_REPO` | detected from git                                          | Override the repo tag; empty means none |
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
