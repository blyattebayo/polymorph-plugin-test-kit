<?php

declare(strict_types=1);

namespace Polymorph\Sdk\Testing\Data;

use Polymorph\Sdk\Data\DefinitionRef;
use Polymorph\Sdk\Data\DefinitionRegistry;
use Polymorph\Sdk\Data\SchemaSpec;

/**
 * Фейковый {@see DefinitionRegistry}: запоминает объявленные сущности и выдаёт
 * стабильные {@see DefinitionRef}. Схемы в тестах не материализуются — данные
 * живут в {@see InMemoryRepository}.
 */
final class FakeDefinitionRegistry implements DefinitionRegistry
{
    /** @var array<string, DefinitionRef> */
    private array $defs = [];

    private int $seq = 0;

    public function ensure(string $entity, SchemaSpec $spec): DefinitionRef
    {
        if (! isset($this->defs[$entity])) {
            $id = ++$this->seq;
            $this->defs[$entity] = new DefinitionRef($id, $id, $entity);
        }

        return $this->defs[$entity];
    }

    /** @return list<string> объявленные сущности (для ассертов в тестах) */
    public function ensuredEntities(): array
    {
        return array_keys($this->defs);
    }
}
