#!/usr/bin/env bash
# Runs INSIDE the PHP container of run-paired.sh (shares the network namespace of the Redis container: Redis is 127.0.0.1:6379).
#   bash paired-inner.sh <output-directory>
# Sequential, alternating-order pairs; every cell is its own PHP process with a flushed script cache and its own warm-up.
set -euo pipefail
cd /app
out="$1"
here=investigations/evalsha-210/noscript
redis_port=6379; proxy7_port=6403; proxy9_port=6404
# Two proxies in front of the same Redis. 2.5 ms each way measures about 7 ms with rtt-probe.php (the proxy itself adds about 2 ms); 3.5 ms each
# way is the configuration of the first round of the study and measures about 9 ms. Both use TCP_NODELAY (see delay-proxy-nodelay.php).
one_way_7="${STUDY_ONE_WAY_MS_7:-2.5}"; one_way_9="${STUDY_ONE_WAY_MS_9:-3.5}"
settle="${STUDY_SETTLE_SECONDS:-2}"
php "$here/delay-proxy-nodelay.php" "$proxy7_port" 127.0.0.1 "$redis_port" "$one_way_7" &
proxy7_pid=$!
php "$here/delay-proxy-nodelay.php" "$proxy9_port" 127.0.0.1 "$redis_port" "$one_way_9" &
proxy9_pid=$!
trap 'kill "$proxy7_pid" "$proxy9_pid" 2>/dev/null || true' EXIT
sleep 1

probe() { php "$here/rtt-probe.php" 127.0.0.1 "$1" "$2" 300 | tee -a "$out/rtt-probes.jsonl"; }

# set <name> <port> <workers> <requests-per-worker> <pairs>
run_set() {
    local name="$1" port="$2" workers="$3" per="$4" pairs="$5" p order m
    probe "$port" "before-$name"
    for p in $(seq 1 "$pairs"); do
        if (( p % 2 == 1 )); then order="eval evalsha"; else order="evalsha eval"; fi
        local position=1
        for m in $order; do
            local file
            file="$(printf '%s_p%02d_%s.json' "$name" "$p" "$m")"
            echo "$(date -u +%H:%M:%S) $name pair $p position $position mode $m" >&2
            php "$here/paired-contention.php" 127.0.0.1 "$port" "$m" "$workers" "$per" "$name-p$p" > "$out/$file"
            printf '%s\t%s\t%s\t%s\t%s\n' "$name" "$p" "$position" "$m" "$file" >> "$out/manifest.tsv"
            position=$((position + 1))
            sleep "$settle"
        done
    done
    probe "$port" "after-$name"
}

: > "$out/manifest.tsv"
: > "$out/rtt-probes.jsonl"
probe "$redis_port" "loopback-start"
probe "$proxy7_port" "proxy7-start"
probe "$proxy9_port" "proxy9-start"
run_set "rtt7-w8x10"  "$proxy7_port" 8 10 "${PAIRS_RTT7_W8X10:-10}"
run_set "loop-w8x10"  "$redis_port"  8 10 "${PAIRS_LOOP_W8X10:-10}"
run_set "rtt7-w8x40"  "$proxy7_port" 8 40 "${PAIRS_RTT7_W8X40:-6}"
run_set "rtt9-w8x10"  "$proxy9_port" 8 10 "${PAIRS_RTT9_W8X10:-6}"
run_set "loop-w16x25" "$redis_port"  16 25 "${PAIRS_LOOP_W16X25:-6}"
probe "$redis_port" "loopback-end"
probe "$proxy7_port" "proxy7-end"
probe "$proxy9_port" "proxy9-end"
