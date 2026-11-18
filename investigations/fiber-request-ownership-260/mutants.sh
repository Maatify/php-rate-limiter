#!/usr/bin/env bash
# DEC-041 mutation controls (#260). Usage (repository root, clean committed tree): bash investigations/fiber-request-ownership-260/mutants.sh [output-dir]
# Each mutant is ONE textual change to ONE source file; the intended-behaviour tests run on actual PHP_INT_SIZE 8 and 4 against an isolated
# Redis (each run is bounded by `timeout 150` inside the container: a mutant that makes a test hang is recorded as DETECTED-BY-TIMEOUT); the file
# is then restored byte for byte (and verified with git). A mutant that no test detects is a failure of the controls and
# makes the script exit 1; so does any failure to restore. Every output and exit status is kept.
set -uo pipefail
root=$(pwd)
out=${1:-"$root/investigations/fiber-request-ownership-260/raw-mutants"}
mkdir -p "$out"
: > "$out/result.tsv"
tests=(tests/Integration/Redis/FiberRequestOwnershipRedisTest.php tests/Integration/Redis/OwnershipCrossingsRedisTest.php tests/Unit/Service/OwnershipRegistryTest.php tests/Unit/Service/HostStoreOwnershipTest.php)
failed=0
if [ -n "$(git status --porcelain -- src tests)" ]; then echo "working tree not clean in src/tests" > "$out/refused.txt"; exit 1; fi
suite() { # width, logfile
    local w=$1 log=$2 sfx="$$-$RANDOM" img=maatify-rl-php64:local plat=linux/arm64
    if [ "$w" = 32 ]; then img=maatify-rl-php32:local; plat=linux/386; fi
    local net="rl260m-$sfx" rd="rl260m-redis-$sfx"
    docker network create "$net" > /dev/null || return 99
    docker run -d --name "$rd" --network "$net" redis:7.0.15-alpine redis-server --save "" --appendonly no > /dev/null || { docker network rm "$net" > /dev/null 2>&1; return 99; }
    until [ "$(docker exec "$rd" redis-cli ping 2>/dev/null)" = PONG ]; do sleep 0.3; done
    docker run --rm --platform "$plat" --network "$net" -v "$root:/app" -w /app -e REDIS_INTEGRATION_HOST="$rd" -e REDIS_INTEGRATION_PORT=6379 "$img" \
        timeout 150 php -d memory_limit=1G vendor/bin/phpunit "${tests[@]}" > "$log" 2>&1
    local st=$?
    docker rm -fv "$rd" > /dev/null 2>&1; docker network rm "$net" > /dev/null 2>&1
    return $st
}
mutate() { # id, file, search, replace
    python3 - "$2" "$3" "$4" <<'PY'
import sys
path, a, b = sys.argv[1], sys.argv[2], sys.argv[3]
s = open(path).read()
if s.count(a) < 1:
    sys.exit("anchor not found: " + a[:60])
open(path, "w").write(s.replace(a, b, 1))
PY
}
run_mutant() { # id, description, file, search, replace
    local id=$1 desc=$2 file=$3
    if [ -n "${MUTANTS:-}" ] && [[ " $MUTANTS " != *" $id "* ]]; then return; fi
    cp "$file" "$out/$id.orig"
    if ! mutate "$id" "$file" "$4" "$5" 2> "$out/$id.mutate.err"; then
        cp "$out/$id.orig" "$file"; printf '%s\tANCHOR-NOT-FOUND\t-\t-\t%s\n' "$id" "$desc" >> "$out/result.tsv"; failed=1; return
    fi
    suite 64 "$out/$id.php64.log"; local s64=$?
    suite 32 "$out/$id.php32.log"; local s32=$?
    cp "$out/$id.orig" "$file"; rm -f "$out/$id.orig"
    local f64 f32
    f64=$(grep -Eo 'Failures: [0-9]+|Errors: [0-9]+' "$out/$id.php64.log" | tr '\n' ' ')
    f32=$(grep -Eo 'Failures: [0-9]+|Errors: [0-9]+' "$out/$id.php32.log" | tr '\n' ' ')
    local verdict=DETECTED
    if [ "$s64" -eq 124 ] || [ "$s32" -eq 124 ]; then verdict=DETECTED-BY-TIMEOUT; fi
    if [ "$s64" -eq 0 ] || [ "$s32" -eq 0 ]; then verdict=UNDETECTED; failed=1; fi
    printf '%s\t%s\tphp64: %s (exit %s)\tphp32: %s (exit %s)\t%s\n' "$id" "$verdict" "$f64" "$s64" "$f32" "$s32" "$desc" >> "$out/result.tsv"
}
S=src/Service
R=src/Repository/Redis/RedisFullCapabilityStore.php
run_mutant M01 "no entry guard on the engine (exclusive() calls straight through)" $S/RateLimiterEngine.php '        $scope = OwnershipRegistry::acquire($this->resources, $entry, $this->domain);
        try {
            return $call();
        } finally {
            $scope->release();
        }' '        return $call();'
run_mutant M02 "store admission keyed on requestState only (a direct transaction without an open request is never admitted)" $R '        $scope = OwnershipRegistry::admit($this, $entry, $this->ownDomain);
        try {
            return $operation();' '        $scope = $this->requestState === null ? null : OwnershipRegistry::admit($this, $entry, $this->ownDomain);
        try {
            return $operation();'
run_mutant M03 "outcome claim exempt from admission (claimed through the raw executor)" $R 'new CallableRedisCommandExecutor(fn(array $command): mixed => $this->command($command)),
            $this->namespace,
        );
    }' 'new CallableRedisCommandExecutor(fn(array $command): mixed => $this->redis->execute($command)),
            $this->namespace,
        );
    }'
run_mutant M04 "crossing-only identity (the owner context always matches)" $S/CallToken.php '        if ($this->keyIsObject) {
            $held = $this->objectKey?->get();

            return $held !== null && $held === $current;
        }' '        return true;
        if ($this->keyIsObject) {
            $held = $this->objectKey?->get();

            return $held !== null && $held === $current;
        }'
run_mutant M05 "premature release: the slots are released right after the identity lookup" $S/OwnershipRegistry.php '            $token->own($key);
            $ok = true;

            return $scope;' '            $token->own($key);
            $ok = true;
            $scope->release();

            return $scope;'
run_mutant M06 "foreign-record cleanup: clear() empties the slot whoever holds it" $S/OwnershipRegistry.php '        if ($slot->record === $record) {
            $slot->record = null;
            self::settle($resource);
        }' '        $slot->record = null;
        self::settle($resource);'
run_mutant M07 "provider lease dropped from the active scope" $S/CallScope.php '        private ?ExecutionContextProviderInterface $lease,
    ) {}' '        private ?ExecutionContextProviderInterface $lease,
    ) {
        $this->lease = null;
    }'
run_mutant M08 "replacement eligibility dropped (a vacated binding is replaced even with an owner)" $S/OwnershipRegistry.php '                if ($slot->record !== null) {
                    throw new RateLimiterException(sprintf('"'"'Cannot replace the execution context binding of %s: its previous provider is gone but an ownership state still exists on it.'"'"', $resource::class));
                }
                continue; // a vacated binding with no owner, reservation or lookup is replaceable' '                continue; // mutant: replaceable whatever the ownership state'
run_mutant M09 "binding is not failure-atomic (records while validating)" $S/OwnershipRegistry.php '            if (! $slot->bound) {
                continue;
            }
            if (self::sameDomain($slot, $provider)) {
                continue;
            }' '            if (! $slot->bound) {
                $slot->bound = true;
                $slot->defaultDomain = $provider === null;
                $slot->provider = $provider === null ? null : \WeakReference::create($provider);
                continue;
            }
            if (self::sameDomain($slot, $provider)) {
                continue;
            }'
run_mutant M10 "all-or-none broken: a refused acquisition keeps what it reserved" $S/OwnershipRegistry.php '            if (! $ok) {
                $scope->release();
            }' '            if (! $ok && false) {
                $scope->release();
            }'
run_mutant M11 "a Host crossing is never marked (emitter, executor, clock)" $S/OwnershipRegistry.php '        $token = $record->token;
        $token->crossings++;
        try {
            return $hostCall();
        } finally {
            $token->crossings--;
        }' '        return $hostCall();'
run_mutant M12 "the overlap refusal is retried like a conflict (transactRequest does not rethrow it)" $R '            } catch (OverlappingRequestException $exception) {
                // DEC-041: an overlapping call is refused, never retried and never classified as a conflict over stale state.
                throw $exception;
            } catch (\Throwable $exception) {' '            } catch (\Throwable $exception) {'
run_mutant M13 "the simple limiter's policy getters are not Host crossings (F266-10)" $S/FixedWindowSimpleRateLimiter.php '        return OwnershipRegistry::crossing($this->store, $call);' '        return $call();'
run_mutant M14 "the pipeline's policy getters are not Host crossings (F266-10)" $S/EvaluationPipeline.php '        return OwnershipRegistry::crossing($this->store, $call);' '        return $call();'
run_mutant M15 "the engine's policy getters are not Host crossings (F266-10)" $S/RateLimiterEngine.php '        return OwnershipRegistry::crossing($this, $call);' '        return $call();'
run_mutant M16 "increment() reads the store clock before its guard (F266-11)" $R "return \$this->guarded('increment', fn() => \$this->incrementAt(\$key, \$ttlSeconds, \$amount, \$this->fallbackNow()));" 'return $this->incrementAt($key, $ttlSeconds, $amount, $this->fallbackNow());'
run_mutant M17 "the store's own clock is not a Host crossing (F266-11)" $R 'return OwnershipRegistry::crossing($this, fn(): int => $this->fallbackClock->now()->getTimestamp());' 'return $this->fallbackClock->now()->getTimestamp();'
run_mutant M18 "the simple reader's Host clock is not a crossing (F266-12)" $S/SimpleRateLimitOperationalReader.php 'return OwnershipRegistry::crossing($this->store, fn(): \DateTimeImmutable => $this->clock->now());' 'return $this->clock->now();'
run_mutant M19 "the score reader's device resolver is not a crossing (F266-12)" $S/RateLimitOperationalReader.php '$device = OwnershipRegistry::crossing($this->store, fn() => $this->deviceResolver->resolve($context));' '$device = $this->deviceResolver->resolve($context);'
run_mutant M20 "a failed reader build keeps the binding it made (F266-13)" src/Builder/RateLimiterBuilder.php '            }
            OwnershipRegistry::rollback($binding);
            throw $failure;
        }
    }

    /**
     * @param list<object> $built' '            }
            throw $failure;
        }
    }

    /**
     * @param list<object> $built'
run_mutant M21 "the inventory trusts a method name of any object (F266-14)" $S/OwnershipRegistry.php '        return in_array((new \ReflectionMethod($object, '"'"'ownedResources'"'"'))->getDeclaringClass()->getName(), self::PACKAGE_OWNED, true);' '        return true;'
run_mutant M22 "the inventory leaves out any object that implements the clock interface, by type (F266-14 follow-up)" $S/OwnershipRegistry.php '            $unique[spl_object_id($object)] = $object;
        }
        foreach ($clocks as $clock) {' '            if ($object instanceof \Maatify\SharedCommon\Contracts\ClockInterface) {
                continue;
            }
            $unique[spl_object_id($object)] = $object;
        }
        foreach ($clocks as $clock) {'
run_mutant M23 "a rollback releases every holder of a binding, not only its own hold (F266-15, F266-17)" $S/OwnershipRegistry.php '            $generation->holders = max(0, $generation->holders - 1);
            if ($created) {' '            $generation->holders = 0;
            if ($created) {'
run_mutant M24 "a hand-assembled raw clock is not a Host crossing (F266-16)" $S/HostClock.php '        return OwnershipRegistry::crossing($this, fn(): DateTimeImmutable => $this->inner->now());' '        return $this->inner->now();'
run_mutant M25 "the breaker keeps a raw Host clock unwrapped (F266-16)" $S/CircuitBreaker.php '        $this->clock = HostClock::of($this->clock);' '        // mutant: unwrapped'
run_mutant M26 "an entry that adopts an existing binding stands on it only in name: no hold is recorded (F266-17 case 1)" $S/OwnershipRegistry.php '            if (! $created) {
                $held[] = new AdoptionHold' '            if (false) {
                $held[] = new AdoptionHold'
run_mutant M27 "a rollback never undoes an abandoned binding (F266-17 case 2)" $S/OwnershipRegistry.php '            if ($created) {
                $generation->creatorRolledBack = true;
            }
            self::settle($resource);' '            if ($created) {
                $generation->creatorRolledBack = true;
            }'
run_mutant M28 "the last release of an active call does not perform the overdue rollback (F266-17 case 3)" $S/OwnershipRegistry.php '            $slot->record = null;
            self::settle($resource);' '            $slot->record = null;'
run_mutant M29 "the overdue rollback undoes a binding while a call is still active on the resource (F266-17 case 3)" $S/OwnershipRegistry.php '        if ($generation === null || $slot->record !== null || $generation->holders > 0 || ! $generation->creatorRolledBack) {' '        if ($generation === null || $generation->holders > 0 || ! $generation->creatorRolledBack) {'
run_mutant M30 "a failed build never releases the holds of its own entries, so it pins its binding (F266-18)" $S/OwnershipRegistry.php '        foreach ($held as $hold) {
            $hold->release();
        }
        unset($map[$entry]);' '        // mutant: the holds of the entry are neither released nor dropped'
run_mutant M31 "a release drops every holder of the binding, the entries of Host code included (F266-17, F266-18)" $S/AdoptionHold.php '        $this->generation->holders = max(0, $this->generation->holders - 1);' '        $this->generation->holders = 0;'
run_mutant M32 "build() does not release the holds of the entries it constructed (F266-18)" src/Builder/RateLimiterBuilder.php '            foreach ($built as $entry) {
                OwnershipRegistry::releaseAdoptions($entry);
            }
            OwnershipRegistry::rollback($binding);' '            OwnershipRegistry::rollback($binding);'
run_mutant M33 "buildOperationalReader() does not release the holds of the readers it constructed (F266-18)" src/Builder/RateLimiterBuilder.php '            // the holds of the readers it constructed itself are released first.
            foreach ($built as $entry) {
                OwnershipRegistry::releaseAdoptions($entry);
            }' '            // mutant: no release'
run_mutant M34 "an entry that died keeps its hold on the binding (review follow-up of F266-20)" $S/AdoptionHold.php '    public function __destruct()
    {
        $this->release();
    }' '    public function __destruct() {}'
cat "$out/result.tsv"
git status --short -- src tests | grep -v '^??' && failed=1
exit "$failed"
