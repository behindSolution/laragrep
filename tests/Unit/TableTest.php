<?php

namespace LaraGrep\Tests\Unit;

use LaraGrep\Config\Column;
use LaraGrep\Config\Relationship;
use LaraGrep\Config\Table;
use PHPUnit\Framework\TestCase;

class TableTest extends TestCase
{
    public function test_make_minimal(): void
    {
        $table = Table::make('users');

        $arr = $table->toArray();

        $this->assertSame('users', $arr['name']);
        $this->assertSame([], $arr['columns']);
        $this->assertArrayNotHasKey('description', $arr);
        $this->assertArrayNotHasKey('relationships', $arr);
    }

    public function test_make_with_description(): void
    {
        $table = Table::make('users')->description('Registered users.');

        $this->assertSame('Registered users.', $table->toArray()['description']);
    }

    public function test_make_with_columns(): void
    {
        $table = Table::make('users')->columns([
            Column::id(),
            Column::string('name'),
        ]);

        $arr = $table->toArray();

        $this->assertCount(2, $arr['columns']);
        $this->assertSame('id', $arr['columns'][0]['name']);
        $this->assertSame('name', $arr['columns'][1]['name']);
    }

    public function test_make_with_plain_array_columns(): void
    {
        $table = Table::make('users')->columns([
            ['name' => 'id', 'type' => 'bigint unsigned'],
            ['name' => 'email', 'type' => 'varchar(255)'],
        ]);

        $arr = $table->toArray();

        $this->assertCount(2, $arr['columns']);
        $this->assertSame('bigint unsigned', $arr['columns'][0]['type']);
    }

    public function test_make_with_relationships(): void
    {
        $table = Table::make('users')->relationships([
            Relationship::hasMany('orders', 'user_id'),
        ]);

        $arr = $table->toArray();

        $this->assertCount(1, $arr['relationships']);
        $this->assertSame('hasMany', $arr['relationships'][0]['type']);
        $this->assertSame('orders', $arr['relationships'][0]['table']);
        $this->assertSame('user_id', $arr['relationships'][0]['foreign_key']);
    }

    public function test_relationships_omitted_when_empty(): void
    {
        $table = Table::make('users');

        $this->assertArrayNotHasKey('relationships', $table->toArray());
    }

    public function test_make_with_connection(): void
    {
        $table = Table::make('external_logs')->connection('secondary');

        $arr = $table->toArray();

        $this->assertSame('secondary', $arr['connection']);
    }

    public function test_connection_omitted_when_null(): void
    {
        $table = Table::make('users');

        $this->assertArrayNotHasKey('connection', $table->toArray());
    }

    public function test_connection_with_engine(): void
    {
        $table = Table::make('analytics')->connection('clickhouse', 'ClickHouse');

        $arr = $table->toArray();

        $this->assertSame('clickhouse', $arr['connection']);
        $this->assertSame('ClickHouse', $arr['engine']);
    }

    public function test_engine_omitted_when_not_provided(): void
    {
        $table = Table::make('logs')->connection('secondary');

        $arr = $table->toArray();

        $this->assertSame('secondary', $arr['connection']);
        $this->assertArrayNotHasKey('engine', $arr);
    }

    public function test_full_fluent_chain(): void
    {
        $table = Table::make('orders')
            ->description('Customer orders.')
            ->connection('reporting', 'MySQL')
            ->columns([
                Column::id(),
                Column::bigInteger('user_id')->unsigned(),
                Column::decimal('total'),
                Column::timestamp('created_at')->nullable(),
            ])
            ->relationships([
                Relationship::belongsTo('users', 'user_id'),
            ]);

        $arr = $table->toArray();

        $this->assertSame('orders', $arr['name']);
        $this->assertSame('Customer orders.', $arr['description']);
        $this->assertSame('reporting', $arr['connection']);
        $this->assertSame('MySQL', $arr['engine']);
        $this->assertCount(4, $arr['columns']);
        $this->assertCount(1, $arr['relationships']);
    }
}
