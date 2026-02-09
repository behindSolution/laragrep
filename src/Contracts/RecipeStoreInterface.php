<?php

namespace LaraGrep\Contracts;

interface RecipeStoreInterface
{
    public function save(array $data): int;

    public function find(int $id): ?object;
}
