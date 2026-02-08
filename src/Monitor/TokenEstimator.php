<?php

namespace LaraGrep\Monitor;

class TokenEstimator
{
    /**
     * Estimate total tokens from a question, its agent loop steps, and the final summary.
     * Uses ~4 chars per token as a rough approximation.
     */
    public function estimateFromSteps(array $steps, string $question, string $summary): int
    {
        $totalChars = mb_strlen($question) + mb_strlen($summary);

        foreach ($steps as $step) {
            $totalChars += mb_strlen($step['query'] ?? '');
            $totalChars += mb_strlen(json_encode($step['bindings'] ?? []));
            $totalChars += mb_strlen(json_encode($step['results'] ?? []));
            $totalChars += mb_strlen($step['reason'] ?? '');
        }

        return (int) ceil($totalChars / 4);
    }
}
