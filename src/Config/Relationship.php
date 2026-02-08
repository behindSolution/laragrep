<?php

namespace LaraGrep\Config;

class Relationship
{
    protected function __construct(
        protected string $type,
        protected string $table,
        protected ?string $foreignKey = null,
    ) {
    }

    public static function belongsTo(string $table, string $foreignKey): static
    {
        return new static('belongsTo', $table, $foreignKey);
    }

    public static function hasMany(string $table, string $foreignKey): static
    {
        return new static('hasMany', $table, $foreignKey);
    }

    public static function hasOne(string $table, string $foreignKey): static
    {
        return new static('hasOne', $table, $foreignKey);
    }

    public static function belongsToMany(
        string $table,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
    ): static {
        return new static(
            'belongsToMany',
            $table,
            "pivot: $pivotTable ($foreignPivotKey, $relatedPivotKey)",
        );
    }

    public function toArray(): array
    {
        $result = [
            'type' => $this->type,
            'table' => $this->table,
        ];

        if ($this->foreignKey !== null) {
            $result['foreign_key'] = $this->foreignKey;
        }

        return $result;
    }
}
