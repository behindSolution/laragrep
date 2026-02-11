<?php

namespace LaraGrep\Config;

class Table
{
    protected string $name;
    protected string $description = '';
    protected ?string $connection = null;
    protected ?string $engine = null;
    protected array $columns = [];
    protected array $relationships = [];

    protected function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function connection(string $connection, ?string $engine = null): static
    {
        $this->connection = $connection;
        $this->engine = $engine;

        return $this;
    }

    public function columns(array $columns): static
    {
        $this->columns = $columns;

        return $this;
    }

    public function relationships(array $relationships): static
    {
        $this->relationships = $relationships;

        return $this;
    }

    public function toArray(): array
    {
        $result = [
            'name' => $this->name,
        ];

        if ($this->description !== '') {
            $result['description'] = $this->description;
        }

        if ($this->connection !== null) {
            $result['connection'] = $this->connection;
        }

        if ($this->engine !== null) {
            $result['engine'] = $this->engine;
        }

        $result['columns'] = array_map(
            fn($col) => $col instanceof Column ? $col->toArray() : $col,
            $this->columns,
        );

        if ($this->relationships !== []) {
            $result['relationships'] = array_map(
                fn($rel) => $rel instanceof Relationship ? $rel->toArray() : $rel,
                $this->relationships,
            );
        }

        return $result;
    }
}
