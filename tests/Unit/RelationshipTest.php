<?php

namespace LaraGrep\Tests\Unit;

use LaraGrep\Config\Relationship;
use PHPUnit\Framework\TestCase;

class RelationshipTest extends TestCase
{
    public function test_belongs_to(): void
    {
        $rel = Relationship::belongsTo('users', 'user_id');

        $this->assertSame([
            'type' => 'belongsTo',
            'table' => 'users',
            'foreign_key' => 'user_id',
        ], $rel->toArray());
    }

    public function test_has_many(): void
    {
        $rel = Relationship::hasMany('orders', 'user_id');

        $this->assertSame([
            'type' => 'hasMany',
            'table' => 'orders',
            'foreign_key' => 'user_id',
        ], $rel->toArray());
    }

    public function test_has_one(): void
    {
        $rel = Relationship::hasOne('profile', 'user_id');

        $this->assertSame([
            'type' => 'hasOne',
            'table' => 'profile',
            'foreign_key' => 'user_id',
        ], $rel->toArray());
    }

    public function test_belongs_to_many(): void
    {
        $rel = Relationship::belongsToMany('roles', 'role_user', 'user_id', 'role_id');

        $arr = $rel->toArray();

        $this->assertSame('belongsToMany', $arr['type']);
        $this->assertSame('roles', $arr['table']);
        $this->assertStringContainsString('role_user', $arr['foreign_key']);
        $this->assertStringContainsString('user_id', $arr['foreign_key']);
        $this->assertStringContainsString('role_id', $arr['foreign_key']);
    }
}
