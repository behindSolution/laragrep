<?php

namespace LaraGrep\Tests\Unit;

use LaraGrep\Prompt\PromptBuilder;
use PHPUnit\Framework\TestCase;

class PromptBuilderReformulationTest extends TestCase
{
    private PromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PromptBuilder();
    }

    public function test_build_reformulation_messages_returns_system_and_user(): void
    {
        $messages = $this->builder->buildReformulationMessages(
            'Show me sales',
            [['question' => 'What period?', 'answer' => 'January 2026']],
        );

        $this->assertCount(2, $messages);
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame('user', $messages[1]['role']);
    }

    public function test_reformulation_system_prompt_mentions_reformulator(): void
    {
        $messages = $this->builder->buildReformulationMessages(
            'Show me sales',
            [['question' => 'What period?', 'answer' => 'January']],
        );

        $this->assertStringContainsString('reformulator', $messages[0]['content']);
    }

    public function test_reformulation_user_prompt_contains_original_question(): void
    {
        $messages = $this->builder->buildReformulationMessages(
            'Mostra as vendas',
            [['question' => 'Qual período?', 'answer' => 'Janeiro 2026']],
        );

        $this->assertStringContainsString('Mostra as vendas', $messages[1]['content']);
    }

    public function test_reformulation_user_prompt_contains_qa_pairs(): void
    {
        $messages = $this->builder->buildReformulationMessages(
            'Show me sales',
            [
                ['question' => 'What period?', 'answer' => 'January 2026'],
                ['question' => 'Which store?', 'answer' => 'Store Centro'],
            ],
        );

        $userContent = $messages[1]['content'];

        $this->assertStringContainsString('What period?', $userContent);
        $this->assertStringContainsString('January 2026', $userContent);
        $this->assertStringContainsString('Which store?', $userContent);
        $this->assertStringContainsString('Store Centro', $userContent);
    }

    public function test_reformulation_user_prompt_contains_user_language(): void
    {
        $messages = $this->builder->buildReformulationMessages(
            'Mostra as vendas',
            [['question' => 'Qual período?', 'answer' => 'Janeiro']],
            'pt-BR',
        );

        $this->assertStringContainsString('pt-BR', $messages[1]['content']);
    }

    public function test_reformulation_user_prompt_instructs_plain_text_response(): void
    {
        $messages = $this->builder->buildReformulationMessages(
            'Show me sales',
            [['question' => 'What period?', 'answer' => 'January']],
        );

        $userContent = $messages[1]['content'];

        $this->assertStringContainsString('No explanations', $userContent);
        $this->assertStringContainsString('no JSON', $userContent);
    }

    public function test_reformulation_with_multiple_qa_pairs_are_numbered(): void
    {
        $messages = $this->builder->buildReformulationMessages(
            'Show me sales',
            [
                ['question' => 'Period?', 'answer' => 'January'],
                ['question' => 'Store?', 'answer' => 'Centro'],
                ['question' => 'Category?', 'answer' => 'Electronics'],
            ],
        );

        $userContent = $messages[1]['content'];

        $this->assertStringContainsString('1. Q: Period?', $userContent);
        $this->assertStringContainsString('2. Q: Store?', $userContent);
        $this->assertStringContainsString('3. Q: Category?', $userContent);
    }
}
