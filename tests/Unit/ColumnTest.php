<?php

namespace LaraGrep\Tests\Unit;

use LaraGrep\Config\Column;
use PHPUnit\Framework\TestCase;

class ColumnTest extends TestCase
{
    public function test_id(): void
    {
        $col = Column::id();

        $this->assertSame([
            'name' => 'id',
            'type' => 'bigint unsigned',
        ], $col->toArray());
    }

    public function test_id_custom_name(): void
    {
        $col = Column::id('user_id');

        $this->assertSame('user_id', $col->toArray()['name']);
    }

    public function test_uuid(): void
    {
        $col = Column::uuid();

        $this->assertSame(['name' => 'uuid', 'type' => 'uuid'], $col->toArray());
    }

    public function test_ulid(): void
    {
        $col = Column::ulid('ulid');

        $this->assertSame(['name' => 'ulid', 'type' => 'ulid'], $col->toArray());
    }

    public function test_string_default_length(): void
    {
        $col = Column::string('name');

        $this->assertSame('varchar(255)', $col->toArray()['type']);
    }

    public function test_string_custom_length(): void
    {
        $col = Column::string('code', 10);

        $this->assertSame('varchar(10)', $col->toArray()['type']);
    }

    public function test_char(): void
    {
        $col = Column::char('currency', 3);

        $this->assertSame('char(3)', $col->toArray()['type']);
    }

    public function test_integer_types(): void
    {
        $this->assertSame('bigint', Column::bigInteger('count')->toArray()['type']);
        $this->assertSame('mediumint', Column::mediumInteger('count')->toArray()['type']);
        $this->assertSame('int', Column::integer('count')->toArray()['type']);
        $this->assertSame('smallint', Column::smallInteger('count')->toArray()['type']);
        $this->assertSame('tinyint', Column::tinyInteger('count')->toArray()['type']);
    }

    public function test_text_types(): void
    {
        $this->assertSame('tinytext', Column::tinyText('note')->toArray()['type']);
        $this->assertSame('text', Column::text('body')->toArray()['type']);
        $this->assertSame('mediumtext', Column::mediumText('content')->toArray()['type']);
        $this->assertSame('longtext', Column::longText('payload')->toArray()['type']);
    }

    public function test_numeric_types(): void
    {
        $this->assertSame('decimal(8,2)', Column::decimal('price')->toArray()['type']);
        $this->assertSame('decimal(10,4)', Column::decimal('rate', 10, 4)->toArray()['type']);
        $this->assertSame('double', Column::double('value')->toArray()['type']);
        $this->assertSame('float', Column::float('score')->toArray()['type']);
    }

    public function test_boolean(): void
    {
        $this->assertSame('boolean', Column::boolean('active')->toArray()['type']);
    }

    public function test_date_time_types(): void
    {
        $this->assertSame('date', Column::date('birth')->toArray()['type']);
        $this->assertSame('time', Column::time('start')->toArray()['type']);
        $this->assertSame('datetime', Column::dateTime('published_at')->toArray()['type']);
        $this->assertSame('timestamp', Column::timestamp('created_at')->toArray()['type']);
        $this->assertSame('year', Column::year('graduation')->toArray()['type']);
    }

    public function test_structured_types(): void
    {
        $this->assertSame('json', Column::json('meta')->toArray()['type']);
        $this->assertSame('jsonb', Column::jsonb('data')->toArray()['type']);
        $this->assertSame('enum(active,inactive)', Column::enum('status', ['active', 'inactive'])->toArray()['type']);
        $this->assertSame('set(read,write)', Column::set('permissions', ['read', 'write'])->toArray()['type']);
    }

    public function test_binary(): void
    {
        $this->assertSame('binary', Column::binary('hash')->toArray()['type']);
    }

    public function test_spatial_types(): void
    {
        $this->assertSame('point', Column::point('location')->toArray()['type']);
        $this->assertSame('geometry', Column::geometry('area')->toArray()['type']);
    }

    public function test_network_types(): void
    {
        $this->assertSame('varchar(45)', Column::ipAddress('ip')->toArray()['type']);
        $this->assertSame('varchar(17)', Column::macAddress('mac')->toArray()['type']);
    }

    // ── Modifiers ───────────────────────────────────────────────────

    public function test_unsigned(): void
    {
        $col = Column::integer('count')->unsigned();

        $this->assertSame('int unsigned', $col->toArray()['type']);
    }

    public function test_nullable(): void
    {
        $col = Column::string('email')->nullable();

        $this->assertSame('varchar(255), nullable', $col->toArray()['type']);
    }

    public function test_unsigned_and_nullable(): void
    {
        $col = Column::integer('count')->unsigned()->nullable();

        $this->assertSame('int unsigned, nullable', $col->toArray()['type']);
    }

    public function test_description(): void
    {
        $col = Column::string('email')->description('User email address.');

        $arr = $col->toArray();
        $this->assertSame('User email address.', $arr['description']);
    }

    public function test_description_omitted_when_empty(): void
    {
        $col = Column::string('email');

        $this->assertArrayNotHasKey('description', $col->toArray());
    }

    public function test_template(): void
    {
        $col = Column::json('meta')->template(['key' => 'value']);

        $arr = $col->toArray();
        $this->assertSame('{"key":"value"}', $arr['template']);
    }

    public function test_template_omitted_when_null(): void
    {
        $col = Column::json('meta');

        $this->assertArrayNotHasKey('template', $col->toArray());
    }

    public function test_fluent_chaining(): void
    {
        $col = Column::string('email', 100)
            ->nullable()
            ->description('Email address')
            ->template(['user@example.com']);

        $arr = $col->toArray();

        $this->assertSame('email', $arr['name']);
        $this->assertSame('varchar(100), nullable', $arr['type']);
        $this->assertSame('Email address', $arr['description']);
        $this->assertSame('["user@example.com"]', $arr['template']);
    }
}
