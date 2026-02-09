<?php

namespace LaraGrep\Tests\Unit;

use LaraGrep\LaraGrep;
use PHPUnit\Framework\TestCase;

class ExtractRecipeTest extends TestCase
{
    private LaraGrep $laraGrep;

    protected function setUp(): void
    {
        // LaraGrep with all null dependencies — only testing extractRecipe() which uses no deps
        $this->laraGrep = new LaraGrep(
            aiClient: $this->createStub(\LaraGrep\Contracts\AiClientInterface::class),
            promptBuilder: $this->createStub(\LaraGrep\Prompt\PromptBuilder::class),
            responseParser: $this->createStub(\LaraGrep\Prompt\ResponseParser::class),
            queryExecutor: $this->createStub(\LaraGrep\Query\QueryExecutor::class),
            queryValidator: $this->createStub(\LaraGrep\Query\QueryValidator::class),
            metadataLoader: null,
            conversationStore: null,
            config: [],
        );
    }

    public function test_extract_recipe_from_successful_steps(): void
    {
        $answer = [
            'summary' => 'Found 5 users.',
            'steps' => [
                [
                    'query' => 'SELECT COUNT(*) FROM users',
                    'bindings' => [],
                    'results' => [['count' => 5]],
                    'reason' => 'Count users',
                ],
                [
                    'query' => 'SELECT name FROM users WHERE active = ?',
                    'bindings' => [1],
                    'results' => [['name' => 'John']],
                    'reason' => 'List active users',
                ],
            ],
        ];

        $recipe = $this->laraGrep->extractRecipe($answer, 'How many users?', 'default');

        $this->assertSame('How many users?', $recipe['question']);
        $this->assertSame('default', $recipe['scope']);
        $this->assertCount(2, $recipe['queries']);
        $this->assertSame('SELECT COUNT(*) FROM users', $recipe['queries'][0]['query']);
        $this->assertSame([1], $recipe['queries'][1]['bindings']);
    }

    public function test_extract_recipe_skips_error_steps(): void
    {
        $answer = [
            'steps' => [
                [
                    'query' => 'SELECT * FROM nonexistent',
                    'bindings' => [],
                    'results' => [],
                    'error' => 'Table not found',
                ],
                [
                    'query' => 'SELECT * FROM users',
                    'bindings' => [],
                    'results' => [['id' => 1]],
                ],
            ],
        ];

        $recipe = $this->laraGrep->extractRecipe($answer, 'test', 'default');

        $this->assertCount(1, $recipe['queries']);
        $this->assertSame('SELECT * FROM users', $recipe['queries'][0]['query']);
    }

    public function test_extract_recipe_skips_empty_results(): void
    {
        $answer = [
            'steps' => [
                [
                    'query' => 'SELECT * FROM users WHERE id = ?',
                    'bindings' => [999],
                    'results' => [],
                ],
            ],
        ];

        $recipe = $this->laraGrep->extractRecipe($answer, 'test');

        $this->assertCount(0, $recipe['queries']);
    }

    public function test_extract_recipe_empty_steps(): void
    {
        $recipe = $this->laraGrep->extractRecipe(['steps' => []], 'test');

        $this->assertSame([], $recipe['queries']);
    }

    public function test_extract_recipe_default_scope(): void
    {
        $recipe = $this->laraGrep->extractRecipe(['steps' => []], 'test');

        $this->assertSame('default', $recipe['scope']);
    }

    public function test_extract_recipe_custom_scope(): void
    {
        $recipe = $this->laraGrep->extractRecipe(['steps' => []], 'test', 'analytics');

        $this->assertSame('analytics', $recipe['scope']);
    }
}
