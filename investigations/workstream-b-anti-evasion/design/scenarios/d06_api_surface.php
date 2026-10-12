<?php

declare(strict_types=1);

// D06 — public-API surface fingerprint of the loaded runtime. Every class, interface, trait and enum of the package namespace is loaded
// from the composer classmap of LAB_ROOT and fingerprinted by reflection: kind, parents, interfaces, constants, public/protected
// properties and public/protected methods (name, static, abstract, final, parameter names/types/defaults/by-ref/variadic, return type).
// PRIVATE members are deliberately excluded: they are not API. The report requires the candidate digest to equal the S0 digest.
require dirname(__DIR__) . '/lib/dsg.php';

emit('D06.provenance', dsgProvenance());

$labRoot = getenv('LAB_ROOT') ?: dirname(__DIR__, 4);
$map = require $labRoot . '/vendor/composer/autoload_classmap.php';
$names = [];
foreach (array_keys($map) as $class) {
    if (str_starts_with((string) $class, 'Maatify\\RateLimiter\\') && ! str_starts_with((string) $class, 'Maatify\\RateLimiter\\Tests\\')) {
        $names[] = (string) $class;
    }
}
sort($names);

/**
 * @param array<string, list<string>> $private collects private member names by class (excluded from the digest)
 * @return array<string, mixed>
 */
function d06Entry(\ReflectionClass $r, array &$private): array
{
    $class = $r->getName();
    $type = static fn(?\ReflectionType $t): string => $t === null ? '-' : (string) $t;
    $entry = [
        'kind' => $r->isInterface() ? 'interface' : ($r->isTrait() ? 'trait' : ($r->isEnum() ? 'enum' : 'class')),
        'abstract' => $r->isAbstract(), 'final' => $r->isFinal(), 'readonly' => $r->isReadOnly(),
        'parent' => $r->getParentClass() === false ? null : $r->getParentClass()->getName(),
        'interfaces' => $r->getInterfaceNames(),
        'constants' => [], 'properties' => [], 'methods' => [],
    ];
    foreach ($r->getReflectionConstants() as $c) {
        if (! $c->isPrivate()) {
            $entry['constants'][] = [$c->getName(), $c->isPublic() ? 'public' : 'protected', $c->getValue() instanceof \UnitEnum ? 'enum' : $c->getValue()];
        }
    }
    foreach ($r->getProperties() as $p) {
        if ($p->getDeclaringClass()->getName() !== $class) {
            continue;
        }
        if ($p->isPrivate()) {
            $private[$class][] = '$' . $p->getName();
            continue;
        }
        $entry['properties'][] = [$p->getName(), $p->isPublic() ? 'public' : 'protected', $p->isStatic(), $p->isReadOnly(), $type($p->getType())];
    }
    foreach ($r->getMethods() as $m) {
        if ($m->getDeclaringClass()->getName() !== $class) {
            continue;
        }
        if ($m->isPrivate()) {
            $private[$class][] = $m->getName() . '()';
            continue;
        }
        $params = [];
        foreach ($m->getParameters() as $p) {
            $default = $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue() instanceof \UnitEnum ? 'enum' : $p->getDefaultValue(), true) : '-';
            $params[] = [$p->getName(), $type($p->getType()), $default, $p->isPassedByReference(), $p->isVariadic()];
        }
        $entry['methods'][] = [$m->getName(), $m->isPublic() ? 'public' : 'protected', $m->isStatic(), $m->isAbstract(), $m->isFinal(), $params, $type($m->getReturnType())];
    }
    usort($entry['methods'], static fn(array $a, array $b): int => strcmp($a[0], $b[0]));
    usort($entry['properties'], static fn(array $a, array $b): int => strcmp($a[0], $b[0]));

    return $entry;
}

$fingerprints = [];
$private = [];
foreach ($names as $class) {
    $fingerprints[$class] = hash('sha256', json_encode(d06Entry(new \ReflectionClass($class), $private), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}
ksort($private);
foreach ($private as &$list) {
    sort($list);
}
unset($list);

emit('D06.surface', [
    'variant' => wsbVariant(),
    'classes' => count($fingerprints),
    'surfaceSha256' => hash('sha256', json_encode($fingerprints, JSON_THROW_ON_ERROR)),
    'perClass' => $fingerprints,
]);
// Private members that are not part of the baseline surface are listed for review (S0 lists the baseline's own private members).
emit('D06.private', ['variant' => wsbVariant(), 'privateMembersByClass' => array_filter($private, static fn(string $c): bool => $c === \Maatify\RateLimiter\Service\EvaluationPipeline::class, ARRAY_FILTER_USE_KEY)]);

// Sensitivity controls: the fingerprint must change when a PUBLIC member is added or a signature changes, and must NOT change when only a
// PRIVATE member is added (otherwise a "no API change" verdict would be vacuous or the private patch could never pass).
eval('namespace DsgCtl; class Base { public function a(int $x): int { return $x; } private function p(): void {} }');
eval('namespace DsgCtl; class AddedPublic { public function a(int $x): int { return $x; } private function p(): void {} public function b(): void {} }');
eval('namespace DsgCtl; class ChangedSignature { public function a(string $x): int { return 1; } private function p(): void {} }');
eval('namespace DsgCtl; class AddedPrivate { public function a(int $x): int { return $x; } private function p(): void {} private function q(): void {} }');
$scratch = [];
$digest = static fn(string $c): string => hash('sha256', json_encode(d06Entry(new \ReflectionClass($c), $scratch), JSON_THROW_ON_ERROR));
WsbAsserts::check('D06', 'control: adding a public method changes the fingerprint', $digest('DsgCtl\\Base') !== $digest('DsgCtl\\AddedPublic'), []);
WsbAsserts::check('D06', 'control: changing a public signature changes the fingerprint', $digest('DsgCtl\\Base') !== $digest('DsgCtl\\ChangedSignature'), []);
WsbAsserts::check('D06', 'control: adding a private method does NOT change the fingerprint', $digest('DsgCtl\\Base') === $digest('DsgCtl\\AddedPrivate'), []);
WsbAsserts::finish();
