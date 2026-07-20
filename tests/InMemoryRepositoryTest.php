<?php

declare(strict_types=1);

namespace Polymorph\Sdk\Testing\Tests;

use Polymorph\Sdk\Testing\Data\InMemoryRepository;

uses()->group('sdk-testing');

it('creates, finds and updates with revision bumps', function (): void {
    $repo = new InMemoryRepository;

    $e = $repo->create(['name' => 'a', 'n' => 1]);
    expect($e->id)->toBe(1)
        ->and($e->revision)->toBe(1)
        ->and($e->string('name'))->toBe('a');

    $updated = $repo->update(1, ['n' => 5]);
    expect($updated->int('n'))->toBe(5)
        ->and($updated->string('name'))->toBe('a')
        ->and($updated->revision)->toBe(2);

    expect($repo->find(1)->int('n'))->toBe(5)
        ->and($repo->find(99))->toBeNull();
});

it('replace overwrites all data; delete removes', function (): void {
    $repo = new InMemoryRepository;
    $repo->create(['a' => 1, 'b' => 2]);

    $replaced = $repo->replace(1, ['a' => 9]);
    expect($replaced->data)->toBe(['a' => 9]);

    $repo->delete(1);
    expect($repo->find(1))->toBeNull()
        ->and($repo->all())->toBe([]);
});

it('increment adds atomically', function (): void {
    $repo = new InMemoryRepository;
    $repo->create(['xp' => 10]);
    expect($repo->increment(1, 'xp', 5)->int('xp'))->toBe(15);
    expect($repo->increment(1, 'xp', -3)->int('xp'))->toBe(12);
});

it('firstOrCreate is idempotent on match', function (): void {
    $repo = new InMemoryRepository;
    $a = $repo->firstOrCreate(['key' => 'k1'], ['v' => 1]);
    $b = $repo->firstOrCreate(['key' => 'k1'], ['v' => 2]);

    expect($b->id)->toBe($a->id)
        ->and($b->int('v'))->toBe(1)
        ->and($repo->all())->toHaveCount(1);
});

it('upsert creates then merges values', function (): void {
    $repo = new InMemoryRepository;
    $repo->upsert(['key' => 'k1'], ['v' => 1]);
    $second = $repo->upsert(['key' => 'k1'], ['v' => 2, 'w' => 3]);

    expect($repo->all())->toHaveCount(1)
        ->and($second->int('v'))->toBe(2)
        ->and($second->int('w'))->toBe(3);
});

it('queries with where/in/null/order/limit', function (): void {
    $repo = new InMemoryRepository;
    $repo->create(['g' => 'x', 'n' => 3]);
    $repo->create(['g' => 'x', 'n' => 1]);
    $repo->create(['g' => 'y', 'n' => 2]);
    $repo->create(['g' => 'x', 'n' => null]);

    expect($repo->query()->where('g', 'x')->count())->toBe(3);
    expect($repo->query()->where('n', '>=', 2)->count())->toBe(2);
    expect($repo->query()->whereIn('g', ['y'])->count())->toBe(1);
    expect($repo->query()->whereNull('n')->count())->toBe(1);
    expect($repo->query()->whereNotNull('n')->count())->toBe(3);

    $ordered = $repo->query()->where('g', 'x')->whereNotNull('n')->orderBy('n')->get();
    expect(array_map(static fn ($e) => $e->int('n'), $ordered))->toBe([1, 3]);

    $top = $repo->query()->orderByDesc('id')->limit(1)->first();
    expect($top->id)->toBe(4);
});

it('aggregates sum/avg and paginates', function (): void {
    $repo = new InMemoryRepository;
    foreach ([10, 20, 30] as $v) {
        $repo->create(['v' => $v]);
    }

    expect($repo->query()->sum('v'))->toBe(60.0)
        ->and($repo->query()->avg('v'))->toBe(20.0);

    $page = $repo->query()->orderBy('id')->paginate(1, 2);
    expect($page->items)->toHaveCount(2)
        ->and($page->pagination->total)->toBe(3)
        ->and($page->pagination->hasMorePages())->toBeTrue();
});

it('whereAuthor filters by stamped author', function (): void {
    $repo = new InMemoryRepository;
    $repo->currentAuthorId = 7;
    $repo->create(['x' => 1]);
    $repo->currentAuthorId = 9;
    $repo->create(['x' => 2]);

    expect($repo->query()->whereAuthor(7)->count())->toBe(1)
        ->and($repo->query()->whereAuthor(7)->first()->int('x'))->toBe(1);
});
