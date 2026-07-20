<?php

declare(strict_types=1);

namespace Polymorph\Sdk\Testing\Access;

use Polymorph\Sdk\Access\AccessGrants;
use Polymorph\Sdk\Access\CapabilityAction;

/**
 * In-memory {@see AccessGrants} для тестов: object-level гранты в массиве.
 * Роли/wildcard не моделируются — только прямые пользовательские гранты.
 */
final class FakeAccessGrants implements AccessGrants
{
    /** @var array<string, true> ключ "userId|action|resource" */
    private array $grants = [];

    public function grantToUser(int $userId, string $resource, string $action = CapabilityAction::ACCESS): void
    {
        $this->grants[$this->key($userId, $action, $resource)] = true;
    }

    public function revokeFromUser(int $userId, string $resource, string $action = CapabilityAction::ACCESS): void
    {
        unset($this->grants[$this->key($userId, $action, $resource)]);
    }

    public function revokeResourceFromAll(array $resources, string $action = CapabilityAction::ACCESS): void
    {
        foreach (array_keys($this->grants) as $key) {
            [, $keyAction, $keyResource] = explode('|', $key, 3);
            if ($keyAction === $action && in_array($keyResource, $resources, true)) {
                unset($this->grants[$key]);
            }
        }
    }

    public function replaceUserGrants(int $userId, string $resourcePrefix, array $resources, string $action = CapabilityAction::ACCESS): void
    {
        foreach (array_keys($this->grants) as $key) {
            [$keyUser, $keyAction, $keyResource] = explode('|', $key, 3);
            if ((int) $keyUser === $userId && $keyAction === $action && str_starts_with($keyResource, $resourcePrefix)) {
                unset($this->grants[$key]);
            }
        }

        foreach ($resources as $resource) {
            $this->grantToUser($userId, $resource, $action);
        }
    }

    public function userCan(int $userId, string $resource, string $action = CapabilityAction::ACCESS): bool
    {
        return isset($this->grants[$this->key($userId, $action, $resource)]);
    }

    public function userCanBatch(int $userId, array $resources, string $action = CapabilityAction::ACCESS): array
    {
        $result = [];
        foreach ($resources as $resource) {
            $result[$resource] = $this->userCan($userId, $resource, $action);
        }

        return $result;
    }

    public function userGrantsByPrefix(string $resourcePrefix, string $action = CapabilityAction::ACCESS): array
    {
        $result = [];
        foreach (array_keys($this->grants) as $key) {
            [$keyUser, $keyAction, $keyResource] = explode('|', $key, 3);
            if ($keyAction === $action && str_starts_with($keyResource, $resourcePrefix)) {
                $result[(int) $keyUser][] = $keyResource;
            }
        }

        return $result;
    }

    private function key(int $userId, string $action, string $resource): string
    {
        return $userId . '|' . $action . '|' . $resource;
    }
}
