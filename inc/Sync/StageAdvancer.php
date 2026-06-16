<?php

declare(strict_types=1);

namespace RhSync\Sync;

/**
 * Eine Operation, die einen Sync-Job stage-weise in Zeit-Häppchen vorantreibt.
 *
 * Der {@see TickRunner} ruft pro Tick genau einmal advance() auf. Die Implementierung
 * (Pull bzw. Push) arbeitet ein Zeitbudget (`$job->tickBudget`) der aktuellen Stage ab,
 * mutiert den JobState (stage, cursor, steps, progress) und speichert ihn. Ist der Job
 * fertig, ruft sie `$job->finishSuccess()`; bei einem Fehler wirft sie eine Exception,
 * die der TickRunner fängt und in `finishFailure()` überführt.
 */
interface StageAdvancer
{
    public function advance(JobState $job): void;
}
