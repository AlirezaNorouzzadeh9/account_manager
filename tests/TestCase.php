<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * A full, healthy check-host sample batch — real responses always come
     * back with exactly CheckHostClient::EXPECTED_SAMPLES (4) entries once a
     * node truly finishes, so fixtures need to match that shape for
     * allNodesOk() to actually evaluate the node instead of skipping it as
     * an incomplete/unreliable read.
     */
    protected static function okPings(float $ms = 0.08): array
    {
        return [['OK', $ms, '1.2.3.4'], ['OK', $ms], ['OK', $ms], ['OK', $ms]];
    }

    protected static function timeoutPings(): array
    {
        return [['TIMEOUT', 3.0], ['TIMEOUT', 3.0], ['TIMEOUT', 3.0], ['TIMEOUT', 3.0]];
    }
}
