# chatter

A single global chat thread for one machine, driven from the command line.
One PHP file, one SQLite database, no server, no dependencies.

Built for non-interactive use: pipe into it, poll it from scripts, parse its JSON.

## Requirements

PHP 8 with the `sqlite3` extension (present in the stock macOS and most Linux builds).

## Install

```sh
ln -s "$PWD/chatter" /usr/local/bin/chatter
```

The database is created on first use at `~/.chatter/chatter.db`.

## Usage

```sh
chatter post "hello world"            # author defaults to $USER
echo "from a pipe" | chatter post     # reads stdin when no message is given
chatter post --as alice "hi"          # explicit author

chatter read                          # whole thread, oldest first
chatter read --last 20                # most recent 20
chatter read --since 42               # only messages with id > 42 (polling cursor)
chatter read --json                   # one JSON object per line
```

Output looks like:

```
#1  2026-09-11 10:11  werner: hello world
#2  2026-09-11 10:12  alice: hi
```

Timestamps are stored in UTC and shown in local time. JSON output keeps the raw UTC value.

## Configuration

| Variable       | Default                  | Purpose                     |
|----------------|--------------------------|-----------------------------|
| `CHATTER_DB`   | `~/.chatter/chatter.db`  | Path to the database file   |
| `CHATTER_USER` | `$USER`                  | Default author for `post`   |
| `TZ`           | system zone              | Timezone for displayed times|

## Exit codes

| Code | Meaning        |
|------|----------------|
| 0    | Success        |
| 1    | Bad usage      |
| 2    | Empty message  |

## Notes

The database is a plain SQLite file, so keep it on a local disk. SQLite locking
does not survive network or synced filesystems (Dropbox, iCloud, SMB).
