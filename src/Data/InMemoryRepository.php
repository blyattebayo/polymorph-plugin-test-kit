<?php

declare(strict_types=1);

namespace Polymorph\Sdk\Testing\Data;

use Polymorph\Sdk\Data\Entity;
use Polymorph\Sdk\Data\EntityPage;
use Polymorph\Sdk\Data\Query;
use Polymorph\Sdk\Data\QueryExecutor;
use Polymorph\Sdk\Data\Repository;
use Polymorph\Sdk\Http\Pagination;

/**
 * In-memory реализация {@see Repository} (+ {@see QueryExecutor}) для тестов
 * расширений — замена V1 InMemoryRecordStore под V2-контракты. Хранит записи в
 * массиве, эмулирует семантику create/find/update/replace/delete/increment/
 * firstOrCreate/upsert и fluent-Query (where/orderBy/limit/paginate/aggregate).
 *
 * @template T of Entity
 * @implements Repository<T>
 */
final class InMemoryRepository implements Repository, QueryExecutor
{
    /** @var array<int, array{data: array<string, mixed>, revision: int, authorId: ?int}> */
    private array $rows = [];

    private int $seq = 0;

    /**
     * Текущий «автор» для стампинга authorId на create (как делает хост из
     * текущего актора). Меняется через {@see PluginTestCase::actingAs()}.
     */
    public ?int $currentAuthorId = null;

    public function create(array $data): Entity
    {
        $id = ++$this->seq;
        $this->rows[$id] = ['data' => $data, 'revision' => 1, 'authorId' => $this->currentAuthorId];

        return $this->entity($id);
    }

    public function find(int $id): ?Entity
    {
        return isset($this->rows[$id]) ? $this->entity($id) : null;
    }

    public function update(int $id, array $partial): Entity
    {
        $this->assertExists($id);
        $this->rows[$id]['data'] = array_replace($this->rows[$id]['data'], $partial);
        $this->rows[$id]['revision']++;

        return $this->entity($id);
    }

    public function replace(int $id, array $data): Entity
    {
        $this->assertExists($id);
        $this->rows[$id]['data'] = $data;
        $this->rows[$id]['revision']++;

        return $this->entity($id);
    }

    public function delete(int $id): void
    {
        unset($this->rows[$id]);
    }

    public function all(): array
    {
        return array_map(fn (int $id): Entity => $this->entity($id), array_keys($this->rows));
    }

    public function query(): Query
    {
        return new Query($this);
    }

    public function increment(int $id, string $field, int|float $delta): Entity
    {
        $this->assertExists($id);
        $current = $this->rows[$id]['data'][$field] ?? 0;
        $this->rows[$id]['data'][$field] = (is_numeric($current) ? $current + $delta : $delta);
        $this->rows[$id]['revision']++;

        return $this->entity($id);
    }

    public function firstOrCreate(array $match, array $defaults = []): Entity
    {
        $existing = $this->findByMatch($match);
        if ($existing !== null) {
            return $existing;
        }

        return $this->create(array_replace($match, $defaults));
    }

    public function upsert(array $match, array $values = []): Entity
    {
        $existing = $this->findByMatch($match);
        if ($existing !== null) {
            return $this->update($existing->id, $values);
        }

        return $this->create(array_replace($match, $values));
    }

    // ───────── QueryExecutor (терминалы Query) ─────────

    public function runGet(Query $query): array
    {
        return array_values(array_map(fn (int $id): Entity => $this->entity($id), $this->matchingIds($query)));
    }

    public function runFirst(Query $query): ?Entity
    {
        $ids = $this->matchingIds($query);
        $first = $ids[0] ?? null;

        return $first !== null ? $this->entity($first) : null;
    }

    public function runExists(Query $query): bool
    {
        return $this->matchingIds($query) !== [];
    }

    public function runCount(Query $query): int
    {
        return count($this->matchingIds($query));
    }

    public function runPaginate(Query $query, int $page, int $perPage): EntityPage
    {
        $ids = $this->matchingIds($query);
        $total = count($ids);
        $slice = array_slice($ids, ($page - 1) * $perPage, $perPage);
        $items = array_map(fn (int $id): Entity => $this->entity($id), $slice);

        return new EntityPage($items, new Pagination($page, $perPage, $total));
    }

    public function runAggregate(Query $query, string $func, string $field): ?float
    {
        $values = [];
        foreach ($this->matchingIds($query) as $id) {
            $value = $this->rows[$id]['data'][$field] ?? null;
            if (is_numeric($value)) {
                $values[] = (float) $value;
            }
        }

        if ($values === []) {
            return $func === 'sum' ? 0.0 : null;
        }

        return match ($func) {
            'sum' => array_sum($values),
            'avg' => array_sum($values) / count($values),
            'min' => min($values),
            'max' => max($values),
            default => null,
        };
    }

    // ───────── internals ─────────

    private function entity(int $id): Entity
    {
        $row = $this->rows[$id];

        return new Entity($id, $row['data'], $row['revision'], $row['authorId']);
    }

    /**
     * @param array<string, mixed> $match
     */
    private function findByMatch(array $match): ?Entity
    {
        foreach (array_keys($this->rows) as $id) {
            if ($this->rowMatchesEquality($id, $match)) {
                return $this->entity($id);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $match
     */
    private function rowMatchesEquality(int $id, array $match): bool
    {
        foreach ($match as $field => $value) {
            if (($this->rows[$id]['data'][$field] ?? null) != $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<int> id записей, прошедших условия запроса (в порядке orders/limit)
     */
    private function matchingIds(Query $query): array
    {
        $ids = [];
        foreach ($this->rows as $id => $row) {
            if ($this->matchesConditions($id, $row, $query) && $this->matchesAuthor($row, $query)) {
                $ids[] = $id;
            }
        }

        $ids = $this->applyOrders($ids, $query->orders());

        $limit = $query->limitValue();
        if ($limit !== null) {
            $ids = array_slice($ids, 0, $limit);
        }

        return array_values($ids);
    }

    /**
     * @param array{data: array<string, mixed>, revision: int, authorId: ?int} $row
     */
    private function matchesConditions(int $id, array $row, Query $query): bool
    {
        foreach ($query->conditions() as $condition) {
            $actual = $this->fieldValue($id, $row, $condition['field']);
            if (! $this->compare($actual, $condition['op'], $condition['value'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Значение поля для фильтра/сортировки. Системные поля 'id'/'author_id'
     * берутся из метаданных строки, прочие — из data.
     *
     * @param array{data: array<string, mixed>, revision: int, authorId: ?int} $row
     */
    private function fieldValue(int $id, array $row, string $field): mixed
    {
        return match ($field) {
            'id' => $id,
            'author_id' => $row['authorId'],
            default => $row['data'][$field] ?? null,
        };
    }

    /**
     * @param array{data: array<string, mixed>, revision: int, authorId: ?int} $row
     */
    private function matchesAuthor(array $row, Query $query): bool
    {
        $author = $query->authorId();

        return $author === null || $row['authorId'] === $author;
    }

    private function compare(mixed $actual, string $op, mixed $value): bool
    {
        return match ($op) {
            '=' => $actual == $value,
            '!=' => $actual != $value,
            '<' => $actual < $value,
            '<=' => $actual <= $value,
            '>' => $actual > $value,
            '>=' => $actual >= $value,
            'in' => is_array($value) && in_array($actual, $value, false),
            'isnull' => $actual === null,
            'notnull' => $actual !== null,
            default => false,
        };
    }

    /**
     * @param list<int> $ids
     * @param list<array{field: string, dir: string}> $orders
     * @return list<int>
     */
    private function applyOrders(array $ids, array $orders): array
    {
        // Зеркалит детерминированный tie-break ядра (EloquentRecordRepository):
        // ничьи разрешаются по системному id, а его направление совпадает с
        // направлением ПОСЛЕДНЕГО заданного порядка (при ASC — id ASC, чтобы не
        // переворачивать хронологию; при DESC или без порядка — id DESC).
        $tieDir = 'desc';
        foreach ($orders as $order) {
            $tieDir = $order['dir'] === 'asc' ? 'asc' : 'desc';
        }

        usort($ids, function (int $a, int $b) use ($orders, $tieDir): int {
            foreach ($orders as $order) {
                $va = $this->fieldValue($a, $this->rows[$a], $order['field']);
                $vb = $this->fieldValue($b, $this->rows[$b], $order['field']);
                $cmp = $va <=> $vb;
                if ($cmp !== 0) {
                    return $order['dir'] === 'desc' ? -$cmp : $cmp;
                }
            }

            return $tieDir === 'desc' ? ($b <=> $a) : ($a <=> $b);
        });

        return $ids;
    }

    private function assertExists(int $id): void
    {
        if (! isset($this->rows[$id])) {
            throw new \RuntimeException("InMemoryRepository: record {$id} does not exist.");
        }
    }
}
