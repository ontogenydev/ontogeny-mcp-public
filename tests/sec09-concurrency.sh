#!/usr/bin/env bash
# Run only against an explicitly authorized WordPress database; dedicated fixture table.
# No mail, no changes to the active plugin, no access to its quota table.
set -Eeuo pipefail
umask 077
root="$(cd -- "$(dirname -- "$0")/.." && pwd -P)"
export MCP021_SOURCE="$root"
export MCP021_DB_WORK="$(mktemp -d "$HOME/mcp-sec09-XXXXXXXX")"
export MCP021_DB_PREFIX="omcpsec09_$(php -r 'echo bin2hex(random_bytes(12));')_"
pids=()
run_wp(){ wp --skip-plugins --skip-themes eval-file "$root/tests/sec09-worker.php"; }
cleanup(){
  rc=$?;trap - EXIT
  for p in "${pids[@]}"; do kill "$p" 2>/dev/null || true; done
  for p in "${pids[@]}"; do wait "$p" 2>/dev/null || true; done
  if [[ -f "$MCP021_DB_WORK/owned" ]]; then
    if ! MCP021_MODE=cleanup run_wp; then echo 'Fixture cleanup failed; retain private work directory and inspect fixture table.' >&2; exit 1; fi
  fi
  if ((rc==0)); then rm -rf -- "$MCP021_DB_WORK"; else printf 'Failure logs retained in %s\n' "$MCP021_DB_WORK" >&2; fi
  exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
MCP021_MODE=setup run_wp
for phase in replay source global throttle; do
  export MCP021_PHASE="$phase"
  MCP021_MODE=reset run_wp
  rm -f -- "$MCP021_DB_WORK/go" "$MCP021_DB_WORK"/ready.* "$MCP021_DB_WORK"/result.*
  n=6; [[ "$phase" != throttle ]] || n=12
  pids=()
  for ((i=1;i<=n;i++)); do
    MCP021_MODE=worker MCP021_WORKER="$i" wp --skip-plugins --skip-themes eval-file "$root/tests/sec09-worker.php" >"$MCP021_DB_WORK/$phase-$i.log" 2>&1 & pids+=("$!")
  done
  for ((tries=0;tries<450;tries++)); do
    count=0; for ((i=1;i<=n;i++)); do [[ ! -f "$MCP021_DB_WORK/ready.$i" ]] || count=$((count+1)); done
    ((count<n)) || break
    sleep 0.1
  done
  ((count==n)) || { echo 'Worker barrier did not complete' >&2; exit 1; }
  touch "$MCP021_DB_WORK/go"
  for p in "${pids[@]}"; do wait "$p"; done
  pids=()
  MCP021_MODE=check run_wp
done
printf 'PASS: SEC-09 four real-database concurrent phases.\n'
