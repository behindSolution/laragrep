<?php

namespace LaraGrep\Tests\Unit;

use LaraGrep\Monitor\TokenEstimator;
use PHPUnit\Framework\TestCase;

class TokenEstimatorTest extends TestCase
{
    private TokenEstimator $estimator;

    protected function setUp(): void
    {
        $this->estimator = new TokenEstimator();
    }

    public function test_estimate_empty(): void
    {
        $result = $this->estimator->estimateFromSteps([], '', '');

        $this->assertSame(0, $result);
    }

    public function test_estimate_question_and_summary(): void
    {
        // 20 chars question + 20 chars summary = 40 chars / 4 = 10 tokens
        $result = $this->estimator->estimateFromSteps([], '12345678901234567890', '12345678901234567890');

        $this->assertSame(10, $result);
    }

    public function test_estimate_rounds_up(): void
    {
        // 5 chars / 4 = 1.25 → ceil = 2
        $result = $this->estimator->estimateFromSteps([], 'hello', '');

        $this->assertSame(2, $result);
    }

    public function test_estimate_includes_steps(): void
    {
        $steps = [
            [
                'query' => 'SELECT * FROM users',
                'bindings' => [1, 2],
                'results' => [['id' => 1, 'name' => 'John']],
                'reason' => 'Fetch users',
            ],
        ];

        $result = $this->estimator->estimateFromSteps($steps, 'question', 'summary');

        $this->assertGreaterThan(0, $result);
    }

    public function test_estimate_handles_missing_step_keys(): void
    {
        $steps = [
            ['query' => 'SELECT 1'],
            [],
        ];

        $result = $this->estimator->estimateFromSteps($steps, '', '');

        $this->assertGreaterThan(0, $result);
    }
}
