<?php

declare(strict_types=1);

namespace Polymorph\Sdk\Testing\Extension;

use Polymorph\Sdk\Access\AccessGrants;
use Polymorph\Sdk\Data\DefinitionRegistry;
use Polymorph\Sdk\Data\Entity;
use Polymorph\Sdk\Data\ExtensionData;
use Polymorph\Sdk\Data\Repository;
use Polymorph\Sdk\Extension\ExtensionContext;
use Polymorph\Sdk\Extension\ExtensionServices;
use Polymorph\Sdk\Testing\Access\FakeAccessGrants;
use Polymorph\Sdk\Testing\Data\FakeDefinitionRegistry;
use Polymorph\Sdk\Testing\Data\InMemoryExtensionData;
use Polymorph\Sdk\Testing\Data\InMemoryRepository;

/**
 * In-memory {@see ExtensionServices} для тестов — фабрика скоупнутых SDK-сервисов
 * поверх фейков. Репозитории кешируются по ключу "{extId}:{entity}", поэтому
 * доступ через records() и data() делит одно состояние в пределах теста.
 */
final class InMemoryExtensionServices implements ExtensionServices
{
    /** @var array<string, InMemoryRepository> */
    private array $repositories = [];

    /** @var array<string, FakeDefinitionRegistry> */
    private array $definitions = [];

    /** @var array<string, FakeAccessGrants> */
    private array $grants = [];

    private ?int $currentAuthorId = null;

    public function repository(ExtensionContext $context, string $entity, string $entityClass = Entity::class): Repository
    {
        $key = $context->id->value.':'.$entity;
        if (! isset($this->repositories[$key])) {
            $repo = new InMemoryRepository;
            $repo->currentAuthorId = $this->currentAuthorId;
            $this->repositories[$key] = $repo;
        }

        return $this->repositories[$key];
    }

    public function data(ExtensionContext $context): ExtensionData
    {
        return new InMemoryExtensionData($this, $context);
    }

    public function definitions(ExtensionContext $context): DefinitionRegistry
    {
        return $this->definitions[$context->id->value] ??= new FakeDefinitionRegistry;
    }

    public function grants(ExtensionContext $context): AccessGrants
    {
        return $this->grants[$context->id->value] ??= new FakeAccessGrants;
    }

    /** Стампит authorId на будущие create() во всех (и новых) репозиториях. */
    public function actingAs(?int $userId): void
    {
        $this->currentAuthorId = $userId;
        foreach ($this->repositories as $repo) {
            $repo->currentAuthorId = $userId;
        }
    }
}
