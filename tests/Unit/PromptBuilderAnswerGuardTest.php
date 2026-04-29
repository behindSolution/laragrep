<?php

namespace LaraGrep\Tests\Unit;

use LaraGrep\Prompt\PromptBuilder;
use PHPUnit\Framework\TestCase;

class PromptBuilderAnswerGuardTest extends TestCase
{
    private PromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PromptBuilder();
    }

    public function test_build_answer_guard_messages_returns_system_and_user(): void
    {
        $messages = $this->builder->buildAnswerGuardMessages(
            'There are 42 users.',
            ['Never mention internal table names'],
            'en',
        );

        $this->assertCount(2, $messages);
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame('user', $messages[1]['role']);
    }

    public function test_answer_guard_system_prompt_describes_role(): void
    {
        $messages = $this->builder->buildAnswerGuardMessages(
            'Some answer.',
            ['Never mention internal table names'],
        );

        $this->assertStringContainsString('answer guard', $messages[0]['content']);
    }

    public function test_answer_guard_user_prompt_contains_rules(): void
    {
        $rules = [
            'Never expose tenant names',
            'Never reveal raw SQL',
        ];

        $messages = $this->builder->buildAnswerGuardMessages(
            'Some answer.',
            $rules,
        );

        $userContent = $messages[1]['content'];

        $this->assertStringContainsString('Never expose tenant names', $userContent);
        $this->assertStringContainsString('Never reveal raw SQL', $userContent);
    }

    public function test_answer_guard_user_prompt_contains_summary(): void
    {
        $messages = $this->builder->buildAnswerGuardMessages(
            'There are 42 active users in the system.',
            ['Never mention internal table names'],
        );

        $this->assertStringContainsString('There are 42 active users in the system.', $messages[1]['content']);
    }

    public function test_answer_guard_user_prompt_includes_user_language(): void
    {
        $messages = $this->builder->buildAnswerGuardMessages(
            'Some answer.',
            ['Some rule'],
            'pt-BR',
        );

        $this->assertStringContainsString('pt-BR', $messages[1]['content']);
    }

    public function test_answer_guard_requests_json_summary(): void
    {
        $messages = $this->builder->buildAnswerGuardMessages(
            'Some answer.',
            ['Some rule'],
        );

        $userContent = $messages[1]['content'];

        $this->assertStringContainsString('"summary"', $userContent);
        $this->assertStringContainsString('JSON object', $userContent);
    }

    public function test_answer_guard_format_instruction_html_default(): void
    {
        $messages = $this->builder->buildAnswerGuardMessages(
            'Some answer.',
            ['Some rule'],
            'en',
            'html',
        );

        $this->assertStringContainsString('HTML tags', $messages[1]['content']);
    }

    public function test_answer_guard_format_instruction_markdown(): void
    {
        $messages = $this->builder->buildAnswerGuardMessages(
            'Some answer.',
            ['Some rule'],
            'en',
            'markdown',
        );

        $this->assertStringContainsString('Markdown', $messages[1]['content']);
    }

    public function test_answer_guard_format_instruction_text(): void
    {
        $messages = $this->builder->buildAnswerGuardMessages(
            'Some answer.',
            ['Some rule'],
            'en',
            'text',
        );

        $this->assertStringContainsString('plain text', $messages[1]['content']);
    }
}
