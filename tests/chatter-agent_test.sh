#!/usr/bin/env bash
# Tests for chatter-agent's testable logic: killtree, cleanup's IFS/trap fix, control()'s
# spawn/kill parsing, and show()'s jq trace filter. Extracts the real function bodies out of
# chatter-agent (it has no importable structure: everything else is top-level, side-effecting
# code) and exercises them with sleep/fifo/mktemp stand-ins, per CLAUDE.md never launching
# chatter-agent or claude for real.
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1
AGENT=./chatter-agent

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

pass=0 fail=0
assert() { if "${@:2}"; then pass=$((pass+1)); else fail=$((fail+1)); echo "FAIL: $1"; fi; }
assert_eq() { if [ "$2" = "$3" ]; then pass=$((pass+1)); else fail=$((fail+1)); echo "FAIL: $1: got '$2' want '$3'"; fi; }
assert_contains() { case $2 in *"$3"*) pass=$((pass+1));; *) fail=$((fail+1)); echo "FAIL: $1: '$2' does not contain '$3'";; esac; }

alive() { kill -0 "$1" 2>/dev/null; }
wait_dead() { local n=0; while alive "$1" && [ "$n" -lt 50 ]; do sleep 0.1; n=$((n+1)); done; ! alive "$1"; }

killtree_src=$(sed -n '/^killtree() {/,/^}/p' "$AGENT")
cleanup_src=$(sed -n '/^cleanup() {/,/^}/p' "$AGENT")
show_src=$(sed -n '/^show() {/,/^}/p' "$AGENT")
control_src=$(sed -n '/^  control() {/,/^  }/p' "$AGENT")
[ -n "$killtree_src" ] || { echo "could not extract killtree() from $AGENT" >&2; exit 1; }
[ -n "$cleanup_src" ] || { echo "could not extract cleanup() from $AGENT" >&2; exit 1; }
[ -n "$show_src" ] || { echo "could not extract show() from $AGENT" >&2; exit 1; }
[ -n "$control_src" ] || { echo "could not extract control() from $AGENT" >&2; exit 1; }

### killtree: kills a whole process tree, but never the caller's own $$ (cleanup relies on this) ###

eval "$killtree_src"

bash -c 'sleep 60 & sleep 60 & wait' &
parent=$!
sleep 0.3
kids=$(pgrep -P "$parent")
killtree "$parent"
tree_dead=1
for p in "$parent" $kids; do wait_dead "$p" || tree_dead=0; done
assert "killtree kills a process and its whole subtree" [ "$tree_dead" = 1 ]

killtree_file="$WORK/killtree_src.sh"
printf '%s\n' "$killtree_src" >"$killtree_file"
selfout="$WORK/self_test.out"
bash -c '
  . "$1"
  sleep 60 &
  child=$!
  killtree "$$"   # here $$ is this process'"'"'s own pid, the same call cleanup makes on itself
  kill -0 "$$" 2>/dev/null && echo "self=alive" || echo "self=dead"
  n=0; while kill -0 "$child" 2>/dev/null && [ "$n" -lt 50 ]; do sleep 0.1; n=$((n+1)); done
  kill -0 "$child" 2>/dev/null && echo "child=alive" || echo "child=dead"
' _ "$killtree_file" >"$selfout"
assert_contains "killtree never kills its own \$\$" "$(cat "$selfout")" "self=alive"
assert_contains "killtree kills a descendant reached via its own \$\$" "$(cat "$selfout")" "child=dead"

### cleanup: the IFS/trap fix. bash 3.2 enters a trap with IFS already emptied by an in-flight
### 'IFS= read', which breaks word-splitting of $spawned unless cleanup resets IFS itself first.
eval "$cleanup_src"
broken_cleanup_src=$(printf '%s\n' "$cleanup_src" | sed '2d')   # same body, minus the IFS=$' \t\n' fix line

run_cleanup_scenario() {  # $1 = cleanup source to use, $2 = file to log kill() calls to
  (
    klog=$2   # a function doesn't close over the caller's $2, so capture it in a plain var first
    # shellcheck disable=SC2329  # called indirectly, by the eval'd cleanup()
    kill() { echo "kill $*" >>"$klog"; }   # shadow the builtin: nothing real gets signalled
    run=$(mktemp -d "$WORK/run.XXXXXX")
    echo 111111 >"$run/devA.pid"
    echo 222222 >"$run/devB.pid"
    # pidfile, fifo, spawned below are read by the eval'd cleanup(), not by this script
    # shellcheck disable=SC2034
    pidfile=$(mktemp "$WORK/pidfile.XXXXXX")
    # shellcheck disable=SC2034
    fifo=""
    # shellcheck disable=SC2034
    spawned="devA devB"
    # shellcheck disable=SC2034
    name=bossX   # cleanup() removes $run/$name.topic
    IFS=''   # simulate the empty IFS a trap can inherit mid-read
    eval "$killtree_src"
    eval "$1"
    cleanup
  )
}

# Count only kills of the two dev pidfiles' pids: cleanup() also does a process-group self-kill
# (`kill -TERM -- -$$`) guarded by a pgid check that depends on whether this test's own subshell
# happens to be a process-group leader, which differs between a local shell and a CI job step. That
# call is orthogonal to the $spawned-splitting bug under test, so it must not count here.
fixed_log=$(mktemp "$WORK/fixed.XXXXXX")
run_cleanup_scenario "$cleanup_src" "$fixed_log"
fixed_kills=$(grep -cE '^kill (111111|222222)$' "$fixed_log")
assert_eq "cleanup (with the IFS fix) kills both spawned devs despite inherited empty IFS" "$fixed_kills" "2"

broken_log=$(mktemp "$WORK/broken.XXXXXX")
run_cleanup_scenario "$broken_cleanup_src" "$broken_log"
broken_kills=$(grep -cE '^kill (111111|222222)$' "$broken_log")
assert "cleanup without the fix mis-splits \$spawned under an inherited empty IFS (regression demo)" [ "$broken_kills" -lt 2 ]

### control(): spawn/kill parsing. "$0" stands in for a re-exec of chatter-agent, replaced here by
### a stub that just logs its argv, so nothing real is ever launched.
control_src_file=$(mktemp "$WORK/control_src.XXXXXX")
printf '%s\n' "$control_src" >"$control_src_file"
stub=$(mktemp "$WORK/stub.XXXXXX")
cat >"$stub" <<'EOS'
#!/usr/bin/env bash
if [ "${1:-}" != __driver__ ]; then
  echo "$*" >>"$STUBLOG"   # this is the "$0 $name $flags" re-exec control() would otherwise do
  exit 0
fi
control_src_file=$2
resultfile=$3
run=$(mktemp -d)
saylog=$(mktemp)
say() { echo "$1" >>"$saylog"; }
pending="" spawned=""
. "$control_src_file"

control spawn "bad;name --x"          # rejected by the name regex before anything else runs
control spawn "myname --topic foo"    # normal spawn: re-execs "$0" with name + flags
sleep 0.3
for i in 1 2 3 4 5; do echo $$ >"$run/cap$i.pid"; done   # 5 alive pidfiles -> at the cap
control spawn "sixth"
sleep 0.2
sleep 50 & realpid=$!
echo "$realpid" >"$run/target.pid"
control kill target
n=0; while kill -0 "$realpid" 2>/dev/null && [ "$n" -lt 30 ]; do sleep 0.1; n=$((n+1)); done
kill -0 "$realpid" 2>/dev/null && killed=no || killed=yes
control kill nosuchdev                # no pidfile for this name

{
  echo "===SAY==="; cat "$saylog"
  echo "===PENDING=[$pending]==="
  echo "===TARGET_KILLED=$killed==="
} >"$resultfile"
EOS
chmod +x "$stub"

STUBLOG=$(mktemp "$WORK/stublog.XXXXXX")
export STUBLOG
resultfile=$(mktemp "$WORK/result.XXXXXX")
"$stub" __driver__ "$control_src_file" "$resultfile"
result=$(cat "$resultfile")
say_section=$(awk '/===SAY===/{f=1;next}/===PENDING/{f=0}f' <<<"$result")
stublog_content=$(cat "$STUBLOG")

assert_contains "control rejects a name with a shell metacharacter" "$say_section" "refused"
assert_contains "control's refusal names the bad input" "$say_section" "bad;name"
assert_contains "control spawns a well-formed name" "$say_section" "spawned dev 'myname'"
assert_contains "control passes the flags through to the re-exec" "$stublog_content" "myname --topic foo"
assert_contains "control tracks a fresh spawn as pending" "$result" "PENDING=[ myname "
assert_contains "control refuses a 6th dev once 5 are already running" "$say_section" "refused spawn of dev 'sixth': 4 devs already running"
assert_contains "control's kill logs success" "$say_section" "killed dev 'target'"
assert_contains "control's kill actually terminates the process" "$result" "TARGET_KILLED=yes"
assert_contains "control refuses to kill a name with no pidfile" "$say_section" "refused kill of dev 'nosuchdev': not running"

### show(): renders claude's stream-json into the trace format the boss/dev turn loop scans for ###
eval "$show_src"

out=$(echo '{"type":"assistant","message":{"content":[{"type":"text","text":"hello world"}]}}' | show)
assert_eq "show renders assistant text as-is" "$out" "hello world"

out=$(echo '{"type":"assistant","message":{"content":[{"type":"tool_use","name":"Bash","input":{"command":"ls -la"}}]}}' | show)
assert_contains "show renders a tool_use line" "$out" "▸ Bash ls -la"

out=$(printf '%s\n' '{"type":"user","message":{"content":[{"type":"tool_result","content":[{"type":"text","text":"line1\nline2"}]}]}}' | show)
assert_contains "show renders a tool_result with newlines squashed" "$out" "↳ line1 line2"

out=$(echo '{"type":"result","is_error":false,"subtype":"success","duration_ms":2500,"total_cost_usd":0.0123}' | show)
assert_contains "show renders a successful turn-done footer" "$out" "■ turn done in 2s"

out=$(echo '{"type":"result","is_error":true,"subtype":"error_max_turns","result":"boom"}' | show)
assert_contains "show flags a failed turn" "$out" "✗ turn failed"
assert_contains "show's failure line includes the reason" "$out" "boom"

### watch_ci: a red verdict lists the final checks table, and a killed gh (boss shutting down) says nothing ###
watch_ci_src=$(sed -n '/^  watch_ci() {/,/^  }/p' "$AGENT")
[ -n "$watch_ci_src" ] || { echo "could not extract watch_ci() from $AGENT" >&2; exit 1; }
eval "$watch_ci_src"
said="$WORK/said"
# shellcheck disable=SC2329  # called indirectly, by the eval'd watch_ci()
say() { printf '%s\n' "$1" >>"$said"; }
# shellcheck disable=SC2329
gh() {  # --watch output starts with a still-pending snapshot; the plain call returns the final table
  if [ "${4:-}" = --watch ]; then printf 'Refreshing checks status every 10 seconds. Press Ctrl+C to quit.\n\nlint\tpending\t0\thttps://x/1\t\n'; return 1; fi
  printf 'lint\tfail\t5s\thttps://x/1\t\ntest\tpass\t3s\thttps://x/2\t\n'
}
watch_ci https://github.com/o/r/pull/9 dev1; wait $!
out=$(cat "$said")
assert_contains "watch_ci addresses a red PR to whoever posted it" "$out" "@dev1: CI is not green on https://github.com/o/r/pull/9"
assert_contains "watch_ci lists the failed check from the final table" "$out" "lint: fail https://x/1"
assert_eq "watch_ci leaves out the stale --watch snapshot and passing checks" "$(grep -c -e pending -e Refreshing -e 'test:' "$said")" 0

rm -f "$said"
# shellcheck disable=SC2329
gh() { return 143; }
watch_ci https://github.com/o/r/pull/9 dev1; wait $!
assert "watch_ci posts nothing when gh is killed" [ ! -s "$said" ]

# a PR's checks take a moment to register: "no checks reported" is retried before it becomes "no CI configured"
calls="$WORK/ghcalls"
rm -f "$said"; : >"$calls"
# shellcheck disable=SC2329
gh() { echo x >>"$calls"; [ "$(wc -l <"$calls")" -ge 2 ] && return 0; echo "no checks reported on the 'b' branch"; return 1; }
# shellcheck disable=SC2329  # sleep is stubbed for the eval'd watch_ci()
( sleep() { :; }; watch_ci https://github.com/o/r/pull/9 dev1; wait $! )
assert_contains "watch_ci retries a PR whose checks have not registered yet" "$(cat "$said")" "status: CI green on"
rm -f "$said"; : >"$calls"
# shellcheck disable=SC2329
gh() { echo x >>"$calls"; echo "no checks reported on the 'b' branch"; return 1; }
# shellcheck disable=SC2329  # sleep is stubbed for the eval'd watch_ci()
( sleep() { :; }; watch_ci https://github.com/o/r/pull/9 dev1; wait $! )
assert_contains "watch_ci calls a PR checkless only after its retries" "$(cat "$said")" "no CI configured"
assert_eq "watch_ci gives up after three tries" "$(wc -l <"$calls" | tr -d ' ')" 3

### status: namespaced by repo, so a same-named worker in two repos doesn't collide and each row says which repo ###

sleep 60 & pidA=$!
sleep 60 & pidB=$!
FAKEHOME="$WORK/home"
mkdir -p "$FAKEHOME/.chatter/run/repoA" "$FAKEHOME/.chatter/run/repoB" "$FAKEHOME/.chatter/logs/repoA"
echo "$pidA" >"$FAKEHOME/.chatter/run/repoA/dev.pid"
echo "topicA" >"$FAKEHOME/.chatter/run/repoA/dev.topic"
echo "$pidB" >"$FAKEHOME/.chatter/run/repoB/dev.pid"
out=$(HOME="$FAKEHOME" "$AGENT" status)
kill "$pidA" "$pidB" 2>/dev/null

assert_contains "status lists repoA's dev with its repo" "$out" "repoA dev dev "
assert_contains "status lists repoB's same-named dev separately" "$out" "repoB dev dev "
assert_contains "status carries repoA's topic through" "$out" "topicA"
assert_eq "status defaults a topic-less repo's dev to -" "$(echo "$out" | grep '^repoB ' | awk '{print $NF}')" "-"

echo "$pass passed, $fail failed"
[ "$fail" -eq 0 ]
