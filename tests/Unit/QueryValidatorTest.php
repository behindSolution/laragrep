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
}
