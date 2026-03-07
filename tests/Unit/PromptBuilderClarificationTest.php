<?php

namespace LaraGrep\Tests\Unit;

use LaraGrep\Prompt\PromptBuilder;
use PHPUnit\Framework\TestCase;

class PromptBuilderClarificationTest extends TestCase
{
    private PromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PromptBuilder();
    }

    public function test_build_clarification_messages_returns_system_and_user(): void
    {
        $tables = [
            ['name' => 'orders', 'description' => 'Customer orders'],
            ['name' => 'stores', 'description' => 'Store locations'],
        ];

        $rules = [
            'Always ask for a date range when the question involves time-based data',
            'Always ask which store if not specified',
        ];

        $messages = $this->builder->buildClarificationMessages(
            'Show me the sales',
            $tables,
            $rules,
            'en',
        );

        $this->assertCount(2, $messages);
        $this->assertSame('system', $messages[0]['role']);
        $this->assertSame('user', $messages[1]['role']);
    }

    public function test_clarification_system_prompt_mentions_analyzer(): void
    {
        $messages = $this->builder->buildClarificationMessages(
            'Show me sales',
            [['name' => 'orders']],
            ['Ask for date range'],
        );

        $this->assertStringContainsString('question analyzer', $messages[0]['content']);
    }

    public function test_clarification_user_prompt_contains_rules(): void
    {
        $rules = [
            'Always ask for period',
            'Always ask for store',
        ];

        $messages = $this->builder->buildClarificationMessages(
            'Show me sales',
            [['name' => 'orders']],
            $rules,
        );

        $userContent = $messages[1]['content'];

        $this->assertStringContainsString('Always ask for period', $userContent);
        $this->assertStringContainsString('Always ask for store', $userContent);
    }

    public function test_clarification_user_prompt_contains_table_names(): void
    {
        $tables = [
            ['name' => 'orders', 'description' => 'Customer orders'],
            ['name' => 'products', 'description' => ''],
        ];

        $messages = $this->builder->buildClarificationMessages(
            'Show me sales',
            $tables,
            ['Ask for date range'],
        );

        $userContent = $messages[1]['content'];

        $this->assertStringContainsString('orders: Customer orders', $userContent);
        $this->assertStringContainsString('- products', $userContent);
    }

    public function test_clarification_user_prompt_contains_question(): void
    {
        $messages = $this->builder->buildClarificationMessages(
            'How many sales last month?',
            [['name' => 'orders']],
            ['Ask for date range'],
        );

        $this->assertStringContainsString('How many sales last month?', $messages[1]['content']);
    }

    public function test_clarification_user_prompt_contains_user_language(): void
    {
        $messages = $this->builder->buildClarificationMessages(
            'Show me sales',
            [['name' => 'orders']],
            ['Ask for date range'],
            'pt-BR',
        );

        $userContent = $messages[1]['content'];

        $this->assertStringContainsString('pt-BR', $userContent);
    }

    public function test_clarification_user_prompt_mentions_response_format(): void
    {
        $messages = $this->builder->buildClarificationMessages(
            'Show me sales',
            [['name' => 'orders']],
            ['Ask for date range'],
        );

        $userContent = $messages[1]['content'];

        $this->assertStringContainsString('"action": "clarification"', $userContent);
        $this->assertStringContainsString('"action": "proceed"', $userContent);
    }
}
