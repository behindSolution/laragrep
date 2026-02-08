<?php

namespace LaraGrep\Config;

class Table
{
    protected string $name;
    protected string $description = '';
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
