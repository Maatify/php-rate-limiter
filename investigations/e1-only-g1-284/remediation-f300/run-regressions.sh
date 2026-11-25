#!/usr/bin/env bash
# Same regression tests, rejected complete src vs current src, isolated real Redis and actual PHP widths.
set -euo pipefail
cd "$(dirname "$0")/../../.."
width="${1:?32 or 64}"
case "$width" in
 32) platform=linux/386; image=maatify-rl-php32:local;;
 64) platform=linux/arm64; image=maatify-rl-php64:local;;
 *) exit 2;;
esac
rejected=304a5835d7d8f1d18834fa94c040515a22a401f5
scratch="$(mktemp -d "${TMPDIR:-/tmp}/f300-regression.XXXXXX")"
network="f300-${width}-$$"; redis_name="${network}-redis"
cleanup() { status=$?; docker rm -f "$redis_name" >/dev/null 2>&1 || true; docker network rm "$network" >/dev/null 2>&1 || true; rm -rf -- "$scratch"; exit "$status"; }
trap cleanup EXIT
git archive "$rejected" src | tar -x -C "$scratch"
cat > "$scratch/bootstrap.php" <<'PHP'
<?php
$loader = require '/app/vendor/autoload.php';
$source = getenv('F300_SOURCE');
$map = [];
foreach ($loader->getClassMap() as $class => $path) {
    if (str_starts_with($class, 'Maatify\\RateLimiter\\') && !str_starts_with($class, 'Maatify\\RateLimiter\\Tests\\')) {
        $map[$class] = $source . '/src/' . str_replace('\\', '/', substr($class, strlen('Maatify\\RateLimiter\\'))) . '.php';
    }
}
$loader->addClassMap($map);
$loader->addPsr4('Maatify\\RateLimiter\\', $source . '/src', true);
$path = (new ReflectionClass(Maatify\RateLimiter\Service\EvaluationPipeline::class))->getFileName();
if ($path !== $source . '/src/Service/EvaluationPipeline.php') {
    throw new RuntimeException('Wrong source arm: ' . $path);
}
echo 'F300 source=' . $source . ' Pipeline.sha256=' . hash_file('sha256', $path) . ' PHP_INT_SIZE=' . PHP_INT_SIZE . PHP_EOL;
PHP
docker network create "$network" >/dev/null
docker run -d --name "$redis_name" --network "$network" redis:7.0.15-alpine redis-server --save '' --appendonly no >/dev/null
until [[ "$(docker exec "$redis_name" redis-cli ping 2>/dev/null || true)" == PONG ]]; do sleep 0.2; done
outdir=investigations/e1-only-g1-284/remediation-f300/raw
{
 echo "rejected=$rejected tree=$(git rev-parse "$rejected^{tree}")"
 echo "checkout=$(git rev-parse HEAD)"
 echo "platform=$platform image=$image"
 docker image inspect "$image" --format '{{.Id}}'
 docker exec "$redis_name" redis-server --version
 shasum -a 256 tests/Integration/Redis/G1ReviewRegressionRedisTest.php tests/Support/Redis/AllowanceOnlyPolicy.php tests/Support/Redis/BudgetSeedlessRedisStore.php src/Service/EvaluationPipeline.php
} > "$outdir/regression-${width}-meta.txt"
for arm in rejected corrected; do
 source=/rejected; [[ "$arm" != corrected ]] || source=/app
 out="$outdir/regression-${width}-${arm}.log"
 set +e
 docker run --rm --platform "$platform" --network "$network" -v "$PWD:/app" -v "$scratch:/rejected:ro" -w /app \
  -e REDIS_INTEGRATION_HOST="$redis_name" -e REDIS_INTEGRATION_PORT=6379 -e F300_SOURCE="$source" "$image" \
  php vendor/bin/phpunit --bootstrap /rejected/bootstrap.php --group g1-review-regression --no-progress > "$out" 2>&1
 status=$?
 set -e
 if [[ "$arm" == rejected ]]; then
  if [[ "$status" != 1 ]] || ! rg -q 'Tests: 17, Assertions: [0-9]+, Failures: 8\.' "$out"; then
   echo "Invalid rejected control: exit=$status"; exit 1
  fi
 else
  if [[ "$status" != 0 ]] || ! rg -q 'OK \(17 tests,' "$out"; then
   echo "Corrected control failed: exit=$status"; exit 1
  fi
 fi
 echo "verified $arm width=$width exit=$status"
done
