<?php

declare(strict_types=1);

// F02 — sensitivity of the SAME candidate (PSC) to the corroboration window cap (test-only knobs PSC_CAP_CHURN / PSC_CAP_DILUTION). This is NOT a
// second design: it measures whether any cap of the one mechanism separates the pairs that the study says are indistinguishable. run.sh
// invokes this script once per knob value; the knob value is recorded in every row. Only the candidate runtime honours the knobs.
require dirname(__DIR__) . '/lib/fsb.php';

emit('F02.provenance', fsbProvenance());
$streams = dsgAllStreams();
$knobName = getenv('FSB_KNOB') ?: '';
$knobValue = (int) (getenv($knobName) ?: 0);
foreach (fsbSensitivityCells() as [$shape, $ids, $flow, $knob, $values]) {
    if ($knob !== $knobName) {
        continue;
    }
    foreach ($ids as $id) {
        emit('F02.sensitivity', ['variant' => wsbVariant(), 'knob' => $knobName, 'cap' => $knobValue, 'stream' => $id, 'shape' => $shape, 'flow' => $flow] + fsbMeasure($streams[$id]['events'], $shape, $flow));
    }
}
WsbAsserts::finish();
