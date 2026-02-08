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

    public static function id(string $name = 'id'): static
    {
        $column = new static($name, 'bigint');
        $column->isUnsigned = true;

        return $column;
    }

    public static function bigInteger(string $name): static
    {
        return new static($name, 'bigint');
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

    public static function string(string $name, int $length = 255): static
    {
        return new static($name, "varchar($length)");
    }

    public static function text(string $name): static
    {
        return new static($name, 'text');
    }

    public static function decimal(string $name, int $precision = 8, int $scale = 2): static
    {
        return new static($name, "decimal($precision,$scale)");
    }

    public static function float(string $name): static
    {
        return new static($name, 'float');
    }

    public static function boolean(string $name): static
    {
        return new static($name, 'boolean');
    }

    public static function date(string $name): static
    {
        return new static($name, 'date');
    }

    public static function dateTime(string $name): static
    {
        return new static($name, 'datetime');
    }

    public static function timestamp(string $name): static
    {
        return new static($name, 'timestamp');
    }

    public static function json(string $name): static
    {
        return new static($name, 'json');
    }

    public static function enum(string $name, array $values): static
    {
        return new static($name, 'enum(' . implode(',', $values) . ')');
    }

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
