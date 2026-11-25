<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../../src/Service/EvaluationPipeline.php');
$anchors = [
    'budget' => ['$this->budgetTracker = new BudgetTracker($accountRate, $budgetClock);', '$this->budgetTracker = new BudgetTracker($this->store, $budgetClock);'],
    'gate' => ['$this->antiEquilibriumGate = new AntiEquilibriumGate($accountCorrelation, $antiEquilibriumClock);', '$this->antiEquilibriumGate = new AntiEquilibriumGate($this->correlationStore, $antiEquilibriumClock);'],
    'bucket' => ['$this->ephemeralBucket = new EphemeralBucket($this->correlationStore, $ephemeralClock);', '$this->ephemeralBucket = new EphemeralBucket($accountCorrelation, $ephemeralClock);'],
];
foreach ($anchors as $name => [$before, $after]) {
    if (substr_count($source, $before) !== 1) {
        throw new RuntimeException('Anchor must match exactly once: ' . $name);
    }
    file_put_contents($argv[1] . '/' . $name . '.php', str_replace($before, $after, $source));
}
file_put_contents($argv[1] . '/bootstrap.php', <<<'BOOT'
<?php
$loader = require '/app/vendor/autoload.php';
$loader->addClassMap(['Maatify\\RateLimiter\\Service\\EvaluationPipeline' => '/mutants/' . getenv('G1_MUTANT') . '.php']);
BOOT);
