<?php

namespace LaraGrep\Config;

class Column
{
    protected string $name;
    protected string $type;
    protected string $description = '';
    protected bool $isUnsigned = false;
    protected bool $isNullable = false;
    protected ?string $template = null;

    protected function __construct(string $name, string $type)
    {
        $this->name = $name;
        $this->type = $type;
    }

    // ── Primary Keys ────────────────────────────────────────────────

    public static function id(string $name = 'id'): static
    {
        $column = new static($name, 'bigint');
        $column->isUnsigned = true;

        return $column;
    }

    public static function uuid(string $name = 'uuid'): static
    {
        return new static($name, 'uuid');
    }

    public static function ulid(string $name = 'ulid'): static
    {
        return new static($name, 'ulid');
    }

    // ── Integers ────────────────────────────────────────────────────

    public static function bigInteger(string $name): static
    {
        return new static($name, 'bigint');
    }

    public static function mediumInteger(string $name): static
    {
        return new static($name, 'mediumint');
    }

    public static function integer(string $name): static
    {
        return new static($name, 'int');
    }

    public static function smallInteger(string $name): static
    {
        return new static($name, 'smallint');
    }

    public static function tinyInteger(string $name): static
    {
        return new static($name, 'tinyint');
    }

    // ── Strings ─────────────────────────────────────────────────────

    public static function char(string $name, int $length = 255): static
    {
        return new static($name, "char($length)");
    }

    public static function string(string $name, int $length = 255): static
    {
        return new static($name, "varchar($length)");
    }

    public static function tinyText(string $name): static
    {
        return new static($name, 'tinytext');
    }

    public static function text(string $name): static
    {
        return new static($name, 'text');
    }

    public static function mediumText(string $name): static
    {
        return new static($name, 'mediumtext');
    }

    public static function longText(string $name): static
    {
        return new static($name, 'longtext');
    }

    // ── Numeric ─────────────────────────────────────────────────────

    public static function decimal(string $name, int $precision = 8, int $scale = 2): static
    {
        return new static($name, "decimal($precision,$scale)");
    }

    public static function double(string $name): static
    {
        return new static($name, 'double');
    }

    public static function float(string $name): static
    {
        return new static($name, 'float');
    }

    // ── Boolean ─────────────────────────────────────────────────────

    public static function boolean(string $name): static
    {
        return new static($name, 'boolean');
    }

    // ── Date & Time ─────────────────────────────────────────────────

    public static function date(string $name): static
    {
        return new static($name, 'date');
    }

    public static function time(string $name): static
    {
        return new static($name, 'time');
    }

    public static function dateTime(string $name): static
    {
        return new static($name, 'datetime');
    }

    public static function timestamp(string $name): static
    {
        return new static($name, 'timestamp');
    }

    public static function year(string $name): static
    {
        return new static($name, 'year');
    }

    // ── Structured ──────────────────────────────────────────────────

    public static function json(string $name): static
    {
        return new static($name, 'json');
    }

    public static function jsonb(string $name): static
    {
        return new static($name, 'jsonb');
    }

    public static function enum(string $name, array $values): static
    {
        return new static($name, 'enum(' . implode(',', $values) . ')');
    }

    public static function set(string $name, array $values): static
    {
        return new static($name, 'set(' . implode(',', $values) . ')');
    }

    // ── Binary ──────────────────────────────────────────────────────

    public static function binary(string $name): static
    {
        return new static($name, 'binary');
    }

    // ── Spatial ─────────────────────────────────────────────────────

    public static function point(string $name): static
    {
        return new static($name, 'point');
    }

    public static function geometry(string $name): static
    {
        return new static($name, 'geometry');
    }

    // ── Network ─────────────────────────────────────────────────────

    public static function ipAddress(string $name): static
    {
        return new static($name, 'varchar(45)');
    }

    public static function macAddress(string $name): static
    {
        return new static($name, 'varchar(17)');
    }

    // ── Modifiers ───────────────────────────────────────────────────

    public function unsigned(): static
    {
        $this->isUnsigned = true;

        return $this;
    }

    public function nullable(): static
    {
        $this->isNullable = true;

        return $this;
    }

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function template(array $structure): static
    {
        $this->template = json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this;
    }

    public function toArray(): array
    {
        $type = $this->type;

        if ($this->isUnsigned) {
            $type .= ' unsigned';
        }

        if ($this->isNullable) {
            $type .= ', nullable';
        }

        $result = [
            'name' => $this->name,
            'type' => $type,
        ];

        if ($this->description !== '') {
            $result['description'] = $this->description;
        }

        if ($this->template !== null) {
            $result['template'] = $this->template;
        }

        return $result;
    }
}
