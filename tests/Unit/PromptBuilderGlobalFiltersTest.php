<?php

namespace LaraGrep\Tests\Unit;

use LaraGrep\Prompt\PromptBuilder;
use PHPUnit\Framework\TestCase;

class PromptBuilderGlobalFiltersTest extends TestCase
{
    private PromptBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PromptBuilder();
    }

    public function test_system_prompt_omits_filters_section_when_none_configured(): void
    {
        $prompt = $this->builder->buildSystemPrompt(
            [['name' => 'users', 'columns' => []]],
            'en',
            null,
            null,
            [],
        );

        $this->assertStringNotContainsString('MANDATORY GLOBAL FILTERS', $prompt);
    }

    public function test_system_prompt_includes_filters_section_when_configured(): void
    {
        $prompt = $this->builder->buildSystemPrompt(
            [['name' => 'incidents', 'columns' => []]],
            'en',
            null,
            null,
            ['incidents' => 'incidents.company_id = 5'],
        );

        $this->assertStringContainsString('MANDATORY GLOBAL FILTERS', $prompt);
        $this->assertStringContainsString('incidents', $prompt);
        $this->assertStringContainsString('incidents.company_id = 5', $prompt);
    }

    public function test_system_prompt_includes_all_configured_filters(): void
    {
        $prompt = $this->builder->buildSystemPrompt(
            [['name' => 'incidents', 'columns' => []], ['name' => 'assets', 'columns' => []]],
            'en',
            null,
            null,
            [
                'incidents' => 'incidents.company_id = 5',
                'assets' => '(assets.id IN (1,2,3))',
            ],
        );

        $this->assertStringContainsString('incidents.company_id = 5', $prompt);
        $this->assertStringContainsString('(assets.id IN (1,2,3))', $prompt);
    }

    public function test_system_prompt_filters_section_warns_about_rejection(): void
    {
        $prompt = $this->builder->buildSystemPrompt(
            [['name' => 'incidents', 'columns' => []]],
            'en',
            null,
            null,
            ['incidents' => 'incidents.company_id = 5'],
        );

        $this->assertStringContainsString('rejected', $prompt);
        $this->assertStringContainsString('retry', $prompt);
    }

    public function test_system_prompt_filters_section_skips_blank_entries(): void
    {
        $prompt = $this->builder->buildSystemPrompt(
            [['name' => 'incidents', 'columns' => []]],
            'en',
            null,
            null,
            ['' => 'something', 'incidents' => '   '],
        );

        $this->assertStringNotContainsString('MANDATORY GLOBAL FILTERS', $prompt);
    }

    public function test_user_prompt_includes_filter_rule_when_filters_present(): void
    {
        $messages = $this->builder->buildQueryMessages(
            'How many incidents?',
            [['name' => 'incidents', 'columns' => []]],
            'en',
            null,
            null,
            [],
            'html',
            0,
            ['incidents' => 'incidents.company_id = 5'],
        );

        $userContent = end($messages)['content'];

        $this->assertStringContainsString('MANDATORY GLOBAL FILTERS', $userContent);
    }

    public function test_user_prompt_omits_filter_rule_when_no_filters(): void
    {
        $messages = $this->builder->buildQueryMessages(
            'How many users?',
            [['name' => 'users', 'columns' => []]],
            'en',
        );

        $userContent = end($messages)['content'];

        $this->assertStringNotContainsString('MANDATORY GLOBAL FILTERS', $userContent);
    }

    public function test_replay_messages_include_filter_section_when_present(): void
    {
        $messages = $this->builder->buildReplayMessages(
            'How many incidents this month?',
            [['name' => 'incidents', 'columns' => []]],
            [['query' => 'SELECT COUNT(*) FROM incidents', 'bindings' => [], 'reason' => 'Count']],
            'en',
            null,
            null,
            'html',
            0,
            ['incidents' => 'incidents.company_id = 5'],
        );

        $systemContent = $messages[0]['content'];

        $this->assertStringContainsString('MANDATORY GLOBAL FILTERS', $systemContent);
        $this->assertStringContainsString('incidents.company_id = 5', $systemContent);
    }
}
