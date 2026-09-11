#!/usr/bin/env php
<?php
// Black-box tests for chatter: runs the real CLI as a subprocess against a scratch SQLite file, never
// ~/.chatter/chatter.db. Plain assert style, no framework: run with `php tests/chatter_test.php`.

$bin = dirname(__DIR__) . '/chatter';
$db = tempnam(sys_get_temp_dir(), 'chatter_test_') . '.db';
register_shutdown_function(function () use ($db) {
    foreach ([$db, "$db-wal", "$db-shm"] as $f) @unlink($f);
});

$baseEnv = [
    'PATH' => getenv('PATH'),
    'CHATTER_DB' => $db,
    'CHATTER_REPO' => 'repoA',   // isolate scope_where repo tests from the real 'chatter' repo
];

$failures = [];
$total = 0;
function check(bool $cond, string $msg): void {
    global $failures, $total;
    $total++;
    if (!$cond) $failures[] = $msg;
    echo ($cond ? "ok - " : "FAIL - ") . $msg . "\n";
}

// Runs `chatter <args>` as a subprocess with the given env (merged over $baseEnv) and stdin; returns [exit code, stdout, stderr].
// $cwd overrides the working directory, e.g. to escape this repo's own .git for a "no repo detected" test.
function chatter(array $args, array $env = [], string $stdin = '', ?string $cwd = null): array {
    global $bin, $baseEnv;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' ' . implode(' ', array_map('escapeshellarg', $args));
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $spec, $pipes, $cwd, array_filter(array_merge($baseEnv, $env), fn($v) => $v !== false));
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
}

// Decodes `read --json` output into a list of row arrays, one per line.
function rows(string $out): array {
    $rows = [];
    foreach (explode("\n", trim($out)) as $line) {
        if ($line !== '') $rows[] = json_decode($line, true);
    }
    return $rows;
}

// ---- post: kind splitting ----

[$code, $out, $err] = chatter(['post', '--as', 'alice', 'hello there'], []);
check($code === 0 && $out === '' && $err === '', 'post: plain message exits 0 with no output');

[, $out] = chatter(['read', '--last', '1', '--json'], ['CHATTER_USER' => 'alice']);
$r = rows($out)[0];
check($r['kind'] === 'chat' && $r['body'] === 'hello there' && $r['author'] === 'alice', 'post: defaults to kind=chat, author from --as');

chatter(['post', '--as', 'bob', 'status: running tests']);
[, $out] = chatter(['read', '--last', '1', '--json']);
$r = rows($out)[0];
check($r['kind'] === 'status' && $r['body'] === 'running tests', 'post: "kind: body" prefix is lifted into the kind column');

chatter(['post', '--as', 'bob', '--kind', 'gotcha', 'status: not actually a prefix here']);
[, $out] = chatter(['read', '--last', '1', '--json']);
$r = rows($out)[0];
check($r['kind'] === 'gotcha' && $r['body'] === 'status: not actually a prefix here', '--kind overrides prefix-splitting, body kept verbatim');

chatter(['post', '--as', 'bob', 'see http://example.com for details']);
[, $out] = chatter(['read', '--last', '1', '--json']);
$r = rows($out)[0];
check($r['kind'] === 'chat' && $r['body'] === 'see http://example.com for details', 'post: a URL colon (no space after) is not mistaken for a kind prefix');

[$code, , $err] = chatter(['post', '--as', 'bob', '--kind', 'BadKind', 'x']);
check($code === 1 && str_contains($err, 'kind must be a lowercase word'), 'post: rejects a non-lowercase --kind');

[$code, , $err] = chatter(['post', '--as', 'bob'], [], '   ');
check($code === 2 && str_contains($err, 'empty message'), 'post: empty stdin body is rejected');

[$code, $out] = chatter(['post', '--as', 'bob'], [], "from stdin\n");
check($code === 0, 'post: reads the message from stdin when no positional arg is given');
[, $out] = chatter(['read', '--last', '1', '--json']);
check(rows($out)[0]['body'] === 'from stdin', 'post: stdin body is trimmed and stored');

// ---- post: reply-to ----

[, $out] = chatter(['read', '--last', '1', '--json']);
$parentId = rows($out)[0]['id'];
chatter(['post', '--as', 'bob', '--reply-to', (string)$parentId, 'agreed']);
[, $out] = chatter(['read', '--last', '1', '--json']);
check(rows($out)[0]['reply_to'] === $parentId, 'post: --reply-to is stored on the row');

[$code, , $err] = chatter(['post', '--as', 'bob', '--reply-to', 'abc', 'x']);
check($code === 1 && str_contains($err, 'existing message'), 'post: --reply-to rejects a non-numeric id');

[$code, , $err] = chatter(['post', '--as', 'bob', '--reply-to', '999999', 'x']);
check($code === 1 && str_contains($err, 'existing message'), 'post: --reply-to rejects an id that does not exist');

// ---- post: "--" ends option parsing ----

[$code, $out, $err] = chatter(['post', '--as', 'bob', '--', '--this-looks-like-a-flag']);
check($code === 0 && $err === '', 'post: "--" lets a body starting with "--" through as an argument');
[, $out] = chatter(['read', '--last', '1', '--json']);
check(rows($out)[0]['body'] === '--this-looks-like-a-flag', 'post: body after "--" is stored verbatim');

// ---- read: --since / --from / --grep ----

chatter(['post', '--as', 'carol', 'marker message needle']);
[, $out] = chatter(['read', '--since', (string)$parentId, '--json']);
$sinceRows = rows($out);
check(count($sinceRows) >= 1 && end($sinceRows)['body'] === 'marker message needle', 'read: --since only returns rows after that id');

[, $out] = chatter(['read', '--from', 'car', '--json']);
$fromRows = rows($out);
check(count($fromRows) > 0 && array_reduce($fromRows, fn($ok, $r) => $ok && str_starts_with($r['author'], 'car'), true), 'read: --from matches authors by prefix');

[, $out] = chatter(['read', '--grep', 'NEEDLE', '--json']);
check(count(rows($out)) > 0 && str_contains(rows($out)[0]['body'], 'needle'), 'read: --grep matches case-insensitively');

chatter(['post', '--as', 'carol', 'grep 100% literal']);
chatter(['post', '--as', 'carol', 'grep 1000 literal']);
[, $out] = chatter(['read', '--grep', '0%', '--json']);
check(array_column(rows($out), 'body') === ['grep 100% literal'], 'read: --grep treats % as a literal, not a wildcard');

[$code, , $err] = chatter(['post', '--as', 'bob', '--topc', 'tests', 'lost message']);
[, $out] = chatter(['read', '--grep', 'lost message', '--json']);
check($code === 1 && str_contains($err, 'unknown option --topc') && rows($out) === [], 'post: an unknown flag is refused, not posted as a body');

// ---- read: --unread cursor is per author ----

chatter(['post', '--as', 'dave', 'first for dave to read']);
[, $out1] = chatter(['read', '--unread', '--json'], ['CHATTER_USER' => 'reader1']);
[, $out2] = chatter(['read', '--unread', '--json'], ['CHATTER_USER' => 'reader1']);
check(count(rows($out1)) > 0 && count(rows($out2)) === 0, 'read: --unread cursor advances per author, second call sees nothing new');
chatter(['post', '--as', 'dave', 'second for dave to read']);
[, $out3] = chatter(['read', '--unread', '--json'], ['CHATTER_USER' => 'reader1']);
check(count(rows($out3)) === 1 && rows($out3)[0]['body'] === 'second for dave to read', 'read: --unread picks up only what arrived since the last read');

// same author name, different repos (e.g. every "boss" process): cursors must not collide
chatter(['post', '--as', 'dave', 'third for dave to read'], ['CHATTER_REPO' => 'repoUnread2']);
chatter(['read', '--unread', '--json'], ['CHATTER_USER' => 'reader1', 'CHATTER_REPO' => 'repoA']);
[, $out4] = chatter(['read', '--unread', '--json'], ['CHATTER_USER' => 'reader1', 'CHATTER_REPO' => 'repoUnread2']);
check(count(rows($out4)) === 1 && rows($out4)[0]['body'] === 'third for dave to read', 'read: --unread cursor is namespaced by repo, not just author name');

// first --unread ever (no cursor row yet) must not dump the whole thread: cap at the last 30, same as the SessionStart hook
for ($i = 1; $i <= 35; $i++) chatter(['post', '--as', 'dave', "flood $i"], ['CHATTER_REPO' => 'repoFlood']);
[, $out5] = chatter(['read', '--unread', '--json'], ['CHATTER_USER' => 'reader2', 'CHATTER_REPO' => 'repoFlood']);
check(count(rows($out5)) === 30 && rows($out5)[0]['body'] === 'flood 6', 'read: --unread with no prior cursor returns only the last 30');
chatter(['post', '--as', 'dave', 'flood 36'], ['CHATTER_REPO' => 'repoFlood']);
[, $out6] = chatter(['read', '--unread', '--json'], ['CHATTER_USER' => 'reader2', 'CHATTER_REPO' => 'repoFlood']);
check(count(rows($out6)) === 1 && rows($out6)[0]['body'] === 'flood 36', 'read: --unread cursor still advances normally after the capped first read');

// ---- scope_where: topic + repo filtering ----

// A dedicated repo tag so this block's topic scoping isn't polluted by the untagged posts earlier tests made under repoA.
$freshEnv = ['CHATTER_REPO' => 'repoScope'];
chatter(['post', '--as', 'x', '--topic', 'topicX', 'in topic X'], $freshEnv);
chatter(['post', '--as', 'x', '--topic', 'topicY', 'in topic Y'], $freshEnv);
chatter(['post', '--as', 'x', 'untagged topic'], $freshEnv);
chatter(['post', '--as', 'x', '--topic', 'topicX', 'done: cross-topic notice'], $freshEnv);

[, $out] = chatter(['read', '--topic', 'topicX', '--json'], $freshEnv);
$bodies = array_column(rows($out), 'body');
check($bodies === ['in topic X', 'cross-topic notice'], 'read: explicit --topic is strict (no untagged, but same-topic done: included)');

[, $out] = chatter(['read', '--json'], array_merge($freshEnv, ['CHATTER_TOPIC' => 'topicY']));
$bodies = array_column(rows($out), 'body');
sort($bodies);
check($bodies === ['cross-topic notice', 'in topic Y', 'untagged topic'], 'read: CHATTER_TOPIC scopes to that topic + untagged + cross-topic kinds (done/decision/question)');

[, $out] = chatter(['read', '--repo', 'repoB', '--json'], $freshEnv);
check(rows($out) === [], 'read: --repo repoB sees none of the repoScope-tagged rows');

[, $out] = chatter(['read', '--repo', 'repoScope', '--json'], $freshEnv);
check(count(rows($out)) === 4, 'read: --repo repoScope sees exactly the repoScope-tagged rows');

chatter(['post', '--as', 'x', '--topic', 'topicX', 'idle: @ydev can you review mine?'], $freshEnv);
[, $out] = chatter(['read', '--json'], array_merge($freshEnv, ['CHATTER_TOPIC' => 'topicY', 'CHATTER_USER' => 'ydev/worktree-ydev']));
check(in_array('@ydev can you review mine?', array_column(rows($out), 'body'), true), 'read: an @mention reaches the named dev from another topic, whatever its kind');
[, $out] = chatter(['read', '--json'], array_merge($freshEnv, ['CHATTER_TOPIC' => 'topicY', 'CHATTER_USER' => 'zdev/worktree-zdev']));
check(!in_array('@ydev can you review mine?', array_column(rows($out), 'body'), true), 'read: ...but not anyone else in that other topic');

// ---- tail: initial snapshot + live follow ----

function readAvailable($stream, float $seconds): string {
    stream_set_blocking($stream, false);
    $out = '';
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $chunk = fread($stream, 65536);
        if ($chunk !== false && $chunk !== '') $out .= $chunk;
        usleep(100_000);
    }
    return $out;
}

chatter(['post', '--as', 'tailer', 'tail seed 1']);
chatter(['post', '--as', 'tailer', 'tail seed 2']);

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' ' . implode(' ', array_map('escapeshellarg', ['tail', '--last', '2', '--json']));
$spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($cmd, $spec, $pipes, null, array_merge($baseEnv, []));
fclose($pipes[0]);
$snapshot = readAvailable($pipes[1], 1.5);
$snapRows = rows($snapshot);
check(count($snapRows) === 2 && $snapRows[1]['body'] === 'tail seed 2', 'tail: --last 2 prints the most recent 2 as an initial snapshot');

chatter(['post', '--as', 'tailer', 'tail live message']);
$live = readAvailable($pipes[1], 3.0);   // tail polls every 2s
check(str_contains($live, 'tail live message'), 'tail: picks up a message posted after it started');

proc_terminate($proc);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);

// --last 0 --once prints no snapshot, blocks until something new arrives, then exits (the case PROTOCOL.md relies on)
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' ' . implode(' ', array_map('escapeshellarg', ['tail', '--last', '0', '--once', '--json']));
$proc = proc_open($cmd, $spec, $pipes, null, array_merge($baseEnv, []));
fclose($pipes[0]);
$initial = readAvailable($pipes[1], 1.0);
check(trim($initial) === '' && proc_get_status($proc)['running'], 'tail: --last 0 --once prints nothing up front and keeps waiting');
chatter(['post', '--as', 'tailer', 'unblocks tail --last 0']);
$after = readAvailable($pipes[1], 3.0);
check(str_contains($after, 'unblocks tail --last 0'), 'tail: --last 0 --once prints the new message');
check(!proc_get_status($proc)['running'], 'tail: --once exits after the first new message');
proc_terminate($proc);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);

// a tail whose parent dies exits by itself, even when nothing in its scope is posted to trip a broken pipe
$sh = 'CHATTER_DB=' . escapeshellarg($db) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' tail --last 0 --repo quiet-repo >/dev/null 2>&1 & echo $!; sleep 1';   // sh outlives the tail's startup, or its first ppid is already 1
$orphan = (int)shell_exec('sh -c ' . escapeshellarg($sh));
usleep(3_500_000);   // one 2s poll after the sh parent exited
check($orphan > 0 && !posix_kill($orphan, 0), 'tail: exits once orphaned by its parent');
if ($orphan > 0) posix_kill($orphan, 15);

// ---- notify: cross-session PostToolUse context ----

[$code, $out] = chatter(['notify'], ['CLAUDE_CODE_SESSION_ID' => 'sessA1111']);
check($code === 0 && trim($out) === '', 'notify: first call for a session sets its cursor and prints nothing');

chatter(['post', '--as', 'other-session'], ['CLAUDE_CODE_SESSION_ID' => 'sessB2222'], 'message from another session');
[, $out] = chatter(['notify'], ['CLAUDE_CODE_SESSION_ID' => 'sessA1111']);
check(str_contains($out, 'message from another session') && str_contains($out, 'hookSpecificOutput'), 'notify: surfaces a message posted by a different session');
$decoded = json_decode($out, true);
check(($decoded['hookSpecificOutput']['hookEventName'] ?? null) === 'PostToolUse', 'notify: emits the PostToolUse hook JSON shape');

chatter(['post', '--as', 'self'], ['CLAUDE_CODE_SESSION_ID' => 'sessA1111'], 'message from my own session');
[, $out] = chatter(['notify'], ['CLAUDE_CODE_SESSION_ID' => 'sessA1111']);
check(!str_contains($out, 'message from my own session'), 'notify: never echoes a session its own messages back');

// ---- whoami: machine-stable repo name for chatter-agent ----

[$code, $out] = chatter(['whoami', '--repo'], ['CHATTER_REPO' => 'someRepo']);
check($code === 0 && $out === "someRepo\n", 'whoami --repo: prints just the repo name');

[$code, $out] = chatter(['whoami', '--repo'], ['CHATTER_REPO' => false], '', sys_get_temp_dir());
check($code === 0 && $out === "\n", 'whoami --repo: prints an empty line when there is no repo');

// ----

printf("\n%d checks, %d failed\n", $total, count($failures));
if ($failures) {
    fwrite(STDERR, "\nFAILED:\n" . implode("\n", $failures) . "\n");
    exit(1);
}
