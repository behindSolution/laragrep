<?php

namespace LaraGrep\Monitor;

class TokenEstimator
{
    /**
     * Estimate total tokens from a question, its agent loop steps, and the final summary.
     *
     * Uses content-aware ratios:
     *  - JSON-heavy (>20% structural chars): ~3 chars/token
     *  - Mixed (10-20%): ~3.5 chars/token
     *  - Plain text: ~4 chars/token
     */
    public function estimateFromSteps(array $steps, string $question, string $summary): int
    {
        $total = $this->estimateTokenCount($question)
            + $this->estimateTokenCount($summary);

        foreach ($steps as $step) {
            $total += $this->estimateTokenCount($step['query'] ?? '');
            $total += $this->estimateTokenCount(json_encode($step['bindings'] ?? []));
            $total += $this->estimateTokenCount(json_encode($step['results'] ?? []));
            $total += $this->estimateTokenCount($step['reason'] ?? '');
        }

        return $total;
    }

    public function estimateTokenCount(string $text): int
    {
        $length = mb_strlen($text);

        if ($length === 0) {
            return 0;
        }

        $structuralCount = preg_match_all('/[{}\[\]":,]/', $text);
        $structuralRatio = $structuralCount / $length;

        $charsPerToken = match (true) {
            $structuralRatio > 0.20 => 3.0,
            $structuralRatio > 0.10 => 3.5,
            default => 4.0,
        };

        return (int) ceil($length / $charsPerToken);
    }
}
