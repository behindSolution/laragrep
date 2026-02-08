<?php

namespace LaraGrep\Contracts;

interface MetadataLoaderInterface
{
    /**
     * @param  string|null  $connection
     * @param  array<int, string>  $excludeTables
     * @return array<int, array{
     *     name: string,
     *     description: string,
     *     columns: array<int, array{name: string, type: string, description: string}>,
     *     relationships?: array<int, array{type: string, table: string, foreign_key?: string}>
     * }>
     */
    public function load(?string $connection = null, array $excludeTables = []): array;
}
