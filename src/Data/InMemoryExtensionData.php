<?php

declare(strict_types=1);

namespace Polymorph\Sdk\Testing\Data;

use Polymorph\Sdk\Data\Entity;
use Polymorph\Sdk\Data\ExtensionData;
use Polymorph\Sdk\Data\Repository;
use Polymorph\Sdk\Extension\ExtensionContext;
use Polymorph\Sdk\Testing\Extension\InMemoryExtensionServices;

/**
 * In-memory {@see ExtensionData} для тестов: мультиплексор над общими
 * per-entity {@see InMemoryRepository} (состояние делится с records()).
 */
final class InMemoryExtensionData implements ExtensionData
{
    public function __construct(
        private readonly InMemoryExtensionServices $services,
        private readonly ExtensionContext $context,
    ) {}

    public function repository(string $entity, string $entityClass = Entity::class): Repository
    {
        return $this->services->repository($this->context, $entity, $entityClass);
    }
}
