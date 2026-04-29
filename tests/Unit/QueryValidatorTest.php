<?php

namespace LaraGrep\Tests\Unit;

use LaraGrep\Query\QueryValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class QueryValidatorTest extends TestCase
{
    private QueryValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new QueryValidator();
    }

    // ── validate: passes ────────────────────────────────────────────

    public function test_valid_select_passes(): void
    {
        $this->validator->validate('SELECT * FROM users', ['users']);

        $this->assertTrue(true); // no exception
    }

    public function test_valid_select_with_join_passes(): void
    {
        $this->validator->validate(
            'SELECT u.name, o.total FROM users u JOIN orders o ON u.id = o.user_id',
            ['users', 'orders']
        );

        $this->assertTrue(true);
    }

    public function test_empty_known_tables_skips_table_check(): void
    {
        $this->validator->validate('SELECT * FROM anything', []);

        $this->assertTrue(true);
    }

    // ── validate: rejects ───────────────────────────────────────────

    public function test_rejects_delete(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only SELECT');

        $this->validator->validate('DELETE FROM users', ['users']);
    }

    public function test_rejects_insert(): void
    {
        $this->expectException(RuntimeException::class);

        $this->validator->validate('INSERT INTO users (name) VALUES (?)', ['users']);
    }

    public function test_rejects_update(): void
    {
        $this->expectException(RuntimeException::class);

        $this->validator->validate('UPDATE users SET name = ?', ['users']);
    }

    public function test_rejects_drop(): void
    {
        $this->expectException(RuntimeException::class);

        $this->validator->validate('DROP TABLE users', ['users']);
    }

    public function test_rejects_unknown_table(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown table');

        $this->validator->validate('SELECT * FROM secrets', ['users', 'orders']);
    }

    // ── extractTableNames ───────────────────────────────────────────

    public function test_extract_simple_from(): void
    {
        $tables = $this->validator->extractTableNames('SELECT * FROM users');

        $this->assertSame(['users'], $tables);
    }

    public function test_extract_join(): void
    {
        $tables = $this->validator->extractTableNames(
            'SELECT * FROM users JOIN orders ON users.id = orders.user_id'
        );

        $this->assertContains('users', $tables);
        $this->assertContains('orders', $tables);
    }

    public function test_extract_multiple_joins(): void
    {
        $tables = $this->validator->extractTableNames(
            'SELECT * FROM users u LEFT JOIN orders o ON u.id = o.user_id INNER JOIN products p ON o.product_id = p.id'
        );

        $this->assertContains('users', $tables);
        $this->assertContains('orders', $tables);
        $this->assertContains('products', $tables);
    }

    public function test_extract_backtick_quoted(): void
    {
        $tables = $this->validator->extractTableNames('SELECT * FROM `users`');

        $this->assertSame(['users'], $tables);
    }

    public function test_extract_with_alias(): void
    {
        $tables = $this->validator->extractTableNames('SELECT * FROM users u');

        $this->assertSame(['users'], $tables);
    }

    public function test_extract_with_schema_prefix(): void
    {
        $tables = $this->validator->extractTableNames('SELECT * FROM mydb.users');

        $this->assertSame(['users'], $tables);
    }

    public function test_extract_deduplicates(): void
    {
        $tables = $this->validator->extractTableNames(
            'SELECT * FROM users JOIN users ON users.id = users.manager_id'
        );

        $this->assertCount(1, $tables);
        $this->assertSame(['users'], $tables);
    }

    // ── CTE handling ─────────────────────────────────────────────────

    public function test_extract_excludes_cte_aliases(): void
    {
        $tables = $this->validator->extractTableNames(
            'WITH monthly AS (SELECT * FROM orders) SELECT * FROM monthly'
        );

        $this->assertSame(['orders'], $tables);
    }

    public function test_extract_excludes_multiple_cte_aliases(): void
    {
        $tables = $this->validator->extractTableNames(
            'WITH user_totals AS (SELECT * FROM users), order_totals AS (SELECT * FROM orders) SELECT * FROM user_totals JOIN order_totals ON 1=1'
        );

        $this->assertContains('users', $tables);
        $this->assertContains('orders', $tables);
        $this->assertNotContains('user_totals', $tables);
        $this->assertNotContains('order_totals', $tables);
    }

    public function test_extract_handles_recursive_cte(): void
    {
        $tables = $this->validator->extractTableNames(
            'WITH RECURSIVE tree AS (SELECT * FROM categories UNION ALL SELECT c.* FROM categories c JOIN tree t ON c.parent_id = t.id) SELECT * FROM tree'
        );

        $this->assertContains('categories', $tables);
        $this->assertNotContains('tree', $tables);
    }

    public function test_validate_passes_with_cte_using_known_tables(): void
    {
        $this->validator->validate(
            'WITH recent AS (SELECT * FROM orders WHERE created_at > ?) SELECT * FROM recent',
            ['orders']
        );

        $this->assertTrue(true);
    }

    // ── String literal / comment stripping ───────────────────────────

    public function test_extract_ignores_table_names_in_string_literals(): void
    {
        $tables = $this->validator->extractTableNames(
            "SELECT * FROM users WHERE description LIKE '%data from secret_table%'"
        );

        $this->assertSame(['users'], $tables);
    }

    public function test_extract_ignores_table_names_in_comments(): void
    {
        $tables = $this->validator->extractTableNames(
            "SELECT * FROM users -- from secret_table"
        );

        $this->assertSame(['users'], $tables);
    }

    public function test_extract_ignores_table_names_in_block_comments(): void
    {
        $tables = $this->validator->extractTableNames(
            "SELECT * FROM users /* JOIN secret_table ON 1=1 */"
        );

        $this->assertSame(['users'], $tables);
    }

    // ── Global filters ───────────────────────────────────────────────

    public function test_global_filter_passes_when_fragment_present(): void
    {
        $this->validator->validate(
            'SELECT * FROM incidents WHERE status = ? AND incidents.company_id = 5',
            ['incidents'],
            0,
            ['incidents' => 'incidents.company_id = 5'],
        );

        $this->assertTrue(true);
    }

    public function test_global_filter_passes_when_table_not_in_query(): void
    {
        // Filter is configured for `incidents`, but query only touches `users` —
        // validation should not require the filter.
        $this->validator->validate(
            'SELECT COUNT(*) FROM users',
            ['users', 'incidents'],
            0,
            ['incidents' => 'incidents.company_id = 5'],
        );

        $this->assertTrue(true);
    }

    public function test_global_filter_rejects_when_fragment_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing the required global filter');

        $this->validator->validate(
            'SELECT * FROM incidents WHERE status = ?',
            ['incidents'],
            0,
            ['incidents' => 'incidents.company_id = 5'],
        );
    }

    public function test_global_filter_error_includes_table_and_fragment(): void
    {
        try {
            $this->validator->validate(
                'SELECT * FROM assets',
                ['assets'],
                0,
                ['assets' => '(assets.id IN (1,2,3) OR assets.company_id != 7)'],
            );

            $this->fail('Expected RuntimeException for missing global filter.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('assets', $e->getMessage());
            $this->assertStringContainsString('(assets.id IN (1,2,3) OR assets.company_id != 7)', $e->getMessage());
        }
    }

    public function test_global_filter_handles_multiple_tables(): void
    {
        $this->validator->validate(
            'SELECT * FROM incidents i JOIN assets a ON a.id = i.asset_id WHERE i.company_id = 5 AND a.company_id = 5',
            ['incidents', 'assets'],
            0,
            [
                'incidents' => 'i.company_id = 5',
                'assets' => 'a.company_id = 5',
            ],
        );

        $this->assertTrue(true);
    }

    public function test_global_filter_rejects_when_only_one_of_many_is_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('assets');

        // incidents filter is present, assets filter is missing
        $this->validator->validate(
            'SELECT * FROM incidents i JOIN assets a ON a.id = i.asset_id WHERE i.company_id = 5',
            ['incidents', 'assets'],
            0,
            [
                'incidents' => 'i.company_id = 5',
                'assets' => 'a.company_id = 5',
            ],
        );
    }

    public function test_global_filter_empty_map_is_noop(): void
    {
        $this->validator->validate(
            'SELECT * FROM users',
            ['users'],
            0,
            [],
        );

        $this->assertTrue(true);
    }

    public function test_global_filter_skips_empty_fragments(): void
    {
        // Empty/whitespace fragments are silently ignored, not enforced.
        $this->validator->validate(
            'SELECT * FROM incidents',
            ['incidents'],
            0,
            ['incidents' => '   '],
        );

        $this->assertTrue(true);
    }

    public function test_global_filter_table_name_is_case_insensitive(): void
    {
        // Filter declared as `Incidents` should match query referencing `incidents`.
        $this->expectException(RuntimeException::class);

        $this->validator->validate(
            'SELECT * FROM incidents',
            ['incidents'],
            0,
            ['Incidents' => 'incidents.company_id = 5'],
        );
    }

    public function test_global_filter_works_with_subquery_table(): void
    {
        $sql = 'SELECT (SELECT COUNT(*) FROM incidents WHERE incidents.company_id = 5) as total FROM users';

        $this->validator->validate(
            $sql,
            ['users', 'incidents'],
            0,
            ['incidents' => 'incidents.company_id = 5'],
        );

        $this->assertTrue(true);
    }
}
