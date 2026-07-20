<?php

declare(strict_types=1);

namespace Polymorph\Sdk\Testing\Contract;

use PHPUnit\Framework\Assert;
use Polymorph\Sdk\Data\Repository;

/**
 * ЕДИНЫЙ контракт поведения {@see Repository}, прогоняемый и против фейка
 * ({@see \Polymorph\Sdk\Testing\Data\InMemoryRepository}), и против реального
 * flexible-адаптера ядра на БД. Это закрывает разрыв «фейк ≡ реальность»: раньше
 * оба тестировались ПАРАЛЛЕЛЬНЫМИ наборами кейсов, которые молча расходились
 * (так проскочил баг tie-break в ордеринге).
 *
 * Контракт покрывает то, что ОБА обязаны гарантировать одинаково: фильтры,
 * ордеринг + детерминированный tie-break, limit, пагинацию, firstOrCreate/upsert.
 * Schema-энфорсмент (required/unique/numeric-тип) сознательно НЕ здесь — фейк его
 * не моделирует намеренно (см. InMemoryRepository).
 *
 * Вызывающий передаёт фабрику ПУСТОГО репозитория сущности с полями
 * track(string), status(string), xp(int).
 */
final class RepositoryContract
{
    /**
     * @param callable(): Repository<\Polymorph\Sdk\Data\Entity> $freshRepo
     */
    public static function assertAll(callable $freshRepo): void
    {
        self::assertTieBreakMirrorsCore($freshRepo);
        self::assertFilterCountLimit($freshRepo);
        self::assertFirstOrCreateUpsert($freshRepo);
        self::assertPaginate($freshRepo);
    }

    /**
     * Главный кейс: при равных значениях сортируемого поля ничьи разрешаются по
     * системному id, а его направление совпадает с направлением последнего order
     * (asc → id asc, desc → id desc). Зеркалит EloquentRecordRepository ядра.
     *
     * @param callable(): Repository<\Polymorph\Sdk\Data\Entity> $freshRepo
     */
    private static function assertTieBreakMirrorsCore(callable $freshRepo): void
    {
        $repo = $freshRepo();
        $a = $repo->create(['track' => 'walk', 'status' => 'completed', 'xp' => 5]);
        $b = $repo->create(['track' => 'walk', 'status' => 'completed', 'xp' => 5]);
        $c = $repo->create(['track' => 'walk', 'status' => 'completed', 'xp' => 5]);

        $desc = array_map(static fn ($e): int => $e->id, $repo->query()->orderByDesc('xp')->get());
        Assert::assertSame([$c->id, $b->id, $a->id], $desc, 'orderByDesc + ties → id DESC');

        Assert::assertSame($c->id, $repo->query()->orderByDesc('xp')->first()?->id, 'first() after desc → highest id');

        $asc = array_map(static fn ($e): int => $e->id, $repo->query()->orderBy('xp')->get());
        Assert::assertSame([$a->id, $b->id, $c->id], $asc, 'orderBy asc + ties → id ASC');
    }

    /**
     * @param callable(): Repository<\Polymorph\Sdk\Data\Entity> $freshRepo
     */
    private static function assertFilterCountLimit(callable $freshRepo): void
    {
        $repo = $freshRepo();
        $repo->create(['track' => 'walk', 'status' => 'completed', 'xp' => 10]);
        $repo->create(['track' => 'walk', 'status' => 'safe_stop', 'xp' => 5]);
        $repo->create(['track' => 'commands', 'status' => 'completed', 'xp' => 20]);

        Assert::assertSame(2, $repo->query()->where('track', 'walk')->count(), 'where eq count');
        Assert::assertSame(2, $repo->query()->whereIn('status', ['completed'])->count(), 'whereIn count');
        Assert::assertSame(2, $repo->query()->where('xp', '>=', 10)->count(), 'where >= count');
        Assert::assertSame(1, count($repo->query()->limit(1)->get()), 'limit caps results');
        Assert::assertTrue($repo->query()->where('track', 'commands')->exists(), 'exists true');
        Assert::assertFalse($repo->query()->where('track', 'zzz')->exists(), 'exists false');
    }

    /**
     * @param callable(): Repository<\Polymorph\Sdk\Data\Entity> $freshRepo
     */
    private static function assertFirstOrCreateUpsert(callable $freshRepo): void
    {
        $repo = $freshRepo();
        $first = $repo->create(['track' => 'walk', 'status' => 'completed', 'xp' => 10]);

        $foc = $repo->firstOrCreate(['track' => 'walk'], ['status' => 'x', 'xp' => 999]);
        Assert::assertSame($first->id, $foc->id, 'firstOrCreate returns existing');
        Assert::assertSame(10, $foc->int('xp'), 'firstOrCreate does not mutate existing');

        $created = $repo->firstOrCreate(['track' => 'newtrack'], ['status' => 'completed', 'xp' => 1]);
        Assert::assertNotSame($first->id, $created->id, 'firstOrCreate creates when missing');

        $ups = $repo->upsert(['track' => 'walk'], ['xp' => 100]);
        Assert::assertSame($first->id, $ups->id, 'upsert updates existing');
        Assert::assertSame(100, $ups->int('xp'), 'upsert applies values');

        Assert::assertSame('fresh', $repo->upsert(['track' => 'fresh'], ['status' => 'completed', 'xp' => 2])->string('track'), 'upsert creates when missing');
    }

    /**
     * @param callable(): Repository<\Polymorph\Sdk\Data\Entity> $freshRepo
     */
    private static function assertPaginate(callable $freshRepo): void
    {
        $repo = $freshRepo();
        $repo->create(['track' => 'walk', 'status' => 'completed', 'xp' => 10]);
        $repo->create(['track' => 'walk', 'status' => 'safe_stop', 'xp' => 5]);
        $repo->create(['track' => 'commands', 'status' => 'completed', 'xp' => 20]);

        $page1 = $repo->query()->paginate(1, 2);
        Assert::assertSame(2, count($page1->items), 'page 1 size');
        Assert::assertSame(3, $page1->pagination->total, 'pagination total');
        Assert::assertTrue($page1->pagination->hasMorePages(), 'page 1 hasMore');
        Assert::assertFalse($repo->query()->paginate(2, 2)->pagination->hasMorePages(), 'page 2 no more');
    }
}
