<?php

namespace LaraGrep\Tests\Unit;

use LaraGrep\Prompt\ResponseParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ResponseParserTest extends TestCase
{
    private ResponseParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ResponseParser();
    }

    // ── parseAction: answer ─────────────────────────────────────────

    public function test_parse_answer_action(): void
    {
        $result = $this->parser->parseAction('{"action": "answer", "summary": "There are 42 users."}');

        $this->assertSame('answer', $result['action']);
        $this->assertSame('There are 42 users.', $result['summary']);
    }

    public function test_parse_answer_strips_markdown_fences(): void
    {
        $result = $this->parser->parseAction("```json\n{\"action\": \"answer\", \"summary\": \"Done.\"}\n```");

        $this->assertSame('answer', $result['action']);
        $this->assertSame('Done.', $result['summary']);
    }

    public function test_parse_answer_without_summary_throws(): void
    {
        $this->expectException(RuntimeException::class);

        $this->parser->parseAction('{"action": "answer", "summary": ""}');
    }

    // ── parseAction: query ──────────────────────────────────────────

    public function test_parse_query_action_single(): void
    {
        $json = json_encode([
            'action' => 'query',
            'queries' => [
                ['query' => 'SELECT COUNT(*) FROM users', 'bindings' => [], 'reason' => 'Count users'],
            ],
        ]);

        $result = $this->parser->parseAction($json);

        $this->assertSame('query', $result['action']);
        $this->assertCount(1, $result['queries']);
        $this->assertSame('SELECT COUNT(*) FROM users', $result['queries'][0]['query']);
        $this->assertSame([], $result['queries'][0]['bindings']);
        $this->assertSame('Count users', $result['queries'][0]['reason']);
    }

    public function test_parse_query_action_multiple(): void
    {
        $json = json_encode([
            'action' => 'query',
            'queries' => [
                ['query' => 'SELECT COUNT(*) FROM users', 'bindings' => []],
                ['query' => 'SELECT COUNT(*) FROM orders', 'bindings' => []],
            ],
        ]);

        $result = $this->parser->parseAction($json);

        $this->assertCount(2, $result['queries']);
    }

    public function test_parse_query_with_bindings(): void
    {
        $json = json_encode([
            'action' => 'query',
            'queries' => [
                ['query' => 'SELECT * FROM users WHERE id = ?', 'bindings' => [42]],
            ],
        ]);

        $result = $this->parser->parseAction($json);

        $this->assertSame([42], $result['queries'][0]['bindings']);
    }

    public function test_parse_query_with_connection(): void
    {
        $json = json_encode([
            'action' => 'query',
            'queries' => [
                ['query' => 'SELECT COUNT(*) FROM logs', 'bindings' => [], 'reason' => 'Count logs', 'connection' => 'secondary'],
            ],
        ]);

        $result = $this->parser->parseAction($json);

        $this->assertSame('secondary', $result['queries'][0]['connection']);
    }

    public function test_parse_query_connection_omitted_when_absent(): void
    {
        $json = json_encode([
            'action' => 'query',
            'queries' => [
                ['query' => 'SELECT COUNT(*) FROM users', 'bindings' => []],
            ],
        ]);

        $result = $this->parser->parseAction($json);

        $this->assertArrayNotHasKey('connection', $result['queries'][0]);
    }

    public function test_parse_query_reason_is_nullable(): void
    {
        $json = json_encode([
            'action' => 'query',
            'queries' => [
                ['query' => 'SELECT 1 FROM users', 'bindings' => []],
            ],
        ]);

        $result = $this->parser->parseAction($json);

        $this->assertNull($result['queries'][0]['reason']);
    }

    public function test_parse_query_rejects_non_select(): void
    {
        $this->expectException(RuntimeException::class);

        $json = json_encode([
            'action' => 'query',
            'queries' => [
                ['query' => 'DELETE FROM users', 'bindings' => []],
            ],
        ]);

        $this->parser->parseAction($json);
    }

    public function test_parse_query_rejects_empty_queries_array(): void
    {
        $this->expectException(RuntimeException::class);

        $this->parser->parseAction('{"action": "query", "queries": []}');
    }

    // ── parseAction: invalid ────────────────────────────────────────

    public function test_parse_invalid_json_throws(): void
    {
        $this->expectException(RuntimeException::class);

        $this->parser->parseAction('not json at all');
    }

    public function test_parse_unknown_action_throws(): void
    {
        $this->expectException(RuntimeException::class);

        $this->parser->parseAction('{"action": "delete"}');
    }

    // ── parseTableSelection ─────────────────────────────────────────

    public function test_parse_action_extracts_first_json_from_concatenated_objects(): void
    {
        $content = '{"action": "answer", "summary": "There are 15 users."}'
            . "\n"
            . '{"action": "query", "queries": [{"query": "SELECT COUNT(*) FROM users", "bindings": []}]}'
            . "\n"
            . '{"action": "answer", "summary": "There are 15 users."}';

        $result = $this->parser->parseAction($content);

        $this->assertSame('answer', $result['action']);
        $this->assertSame('There are 15 users.', $result['summary']);
    }

    public function test_parse_action_extracts_first_json_with_surrounding_text(): void
    {
        $content = 'Here is my response: {"action": "answer", "summary": "Done."} and some trailing text.';

        $result = $this->parser->parseAction($content);

        $this->assertSame('answer', $result['action']);
        $this->assertSame('Done.', $result['summary']);
    }

    // ── parseTableSelection ─────────────────────────────────────────

    public function test_parse_table_selection(): void
    {
        $result = $this->parser->parseTableSelection('{"tables": ["Users", "Orders"]}');

        $this->assertSame(['users', 'orders'], $result);
    }

    public function test_parse_table_selection_strips_fences(): void
    {
        $result = $this->parser->parseTableSelection("```json\n{\"tables\": [\"products\"]}\n```");

        $this->assertSame(['products'], $result);
    }

    public function test_parse_table_selection_filters_empty(): void
    {
        $result = $this->parser->parseTableSelection('{"tables": ["users", "", "  "]}');

        $this->assertSame(['users'], $result);
    }

    public function test_parse_table_selection_invalid_throws(): void
    {
        $this->expectException(RuntimeException::class);

        $this->parser->parseTableSelection('{"result": "users"}');
    }

    // ── parseClarification: proceed ───────────────────────────────

    public function test_parse_clarification_proceed(): void
    {
        $result = $this->parser->parseClarification('{"action": "proceed"}');

        $this->assertSame(['action' => 'proceed'], $result);
    }

    public function test_parse_clarification_proceed_strips_markdown_fences(): void
    {
        $result = $this->parser->parseClarification("```json\n{\"action\": \"proceed\"}\n```");

        $this->assertSame(['action' => 'proceed'], $result);
    }

    // ── parseClarification: clarification ───────────────────────

    public function test_parse_clarification_with_questions(): void
    {
        $json = json_encode([
            'action' => 'clarification',
            'questions' => ['What date range?', 'Which store?'],
        ]);

        $result = $this->parser->parseClarification($json);

        $this->assertSame('clarification', $result['action']);
        $this->assertSame(['What date range?', 'Which store?'], $result['questions']);
    }

    public function test_parse_clarification_filters_empty_questions(): void
    {
        $json = json_encode([
            'action' => 'clarification',
            'questions' => ['What date range?', '', '  '],
        ]);

        $result = $this->parser->parseClarification($json);

        $this->assertSame(['What date range?'], $result['questions']);
    }

    public function test_parse_clarification_extracts_first_json(): void
    {
        $content = 'Here is my analysis: {"action": "clarification", "questions": ["Which period?"]} end.';

        $result = $this->parser->parseClarification($content);

        $this->assertSame('clarification', $result['action']);
        $this->assertSame(['Which period?'], $result['questions']);
    }

    // ── parseClarification: invalid ─────────────────────────────

    public function test_parse_clarification_invalid_json_throws(): void
    {
        $this->expectException(RuntimeException::class);

        $this->parser->parseClarification('not json');
    }

    public function test_parse_clarification_unknown_action_throws(): void
    {
        $this->expectException(RuntimeException::class);

        $this->parser->parseClarification('{"action": "query"}');
    }

    public function test_parse_clarification_empty_questions_throws(): void
    {
        $this->expectException(RuntimeException::class);

        $this->parser->parseClarification('{"action": "clarification", "questions": []}');
    }

    public function test_parse_clarification_missing_questions_throws(): void
    {
        $this->expectException(RuntimeException::class);

        $this->parser->parseClarification('{"action": "clarification"}');
    }

    public function test_parse_clarification_all_blank_questions_throws(): void
    {
        $this->expectException(RuntimeException::class);

        $this->parser->parseClarification(json_encode([
            'action' => 'clarification',
            'questions' => ['', '  '],
        ]));
    }

    // ── parseReformulation ──────────────────────────────────────

    public function test_parse_reformulation_returns_trimmed_text(): void
    {
        $result = $this->parser->parseReformulation('  Mostra as vendas de Janeiro  ');

        $this->assertSame('Mostra as vendas de Janeiro', $result);
    }

    public function test_parse_reformulation_strips_markdown_fences(): void
    {
        $result = $this->parser->parseReformulation("```\nReformulated question\n```");

        $this->assertSame('Reformulated question', $result);
    }

    public function test_parse_reformulation_strips_surrounding_double_quotes(): void
    {
        $result = $this->parser->parseReformulation('"Mostra as vendas da Loja Centro"');

        $this->assertSame('Mostra as vendas da Loja Centro', $result);
    }

    public function test_parse_reformulation_strips_surrounding_single_quotes(): void
    {
        $result = $this->parser->parseReformulation("'Mostra as vendas da Loja Centro'");

        $this->assertSame('Mostra as vendas da Loja Centro', $result);
    }

    public function test_parse_reformulation_empty_throws(): void
    {
        $this->expectException(RuntimeException::class);

        $this->parser->parseReformulation('   ');
    }

    public function test_parse_reformulation_preserves_content(): void
    {
        $question = 'Show me the sales for Store Centro in January 2026, grouped by product category';

        $result = $this->parser->parseReformulation($question);

        $this->assertSame($question, $result);
    }
}
