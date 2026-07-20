<?php

declare(strict_types=1);

namespace Polymorph\Sdk\Testing\Tests;

use Polymorph\Sdk\Testing\Contract\RepositoryContract;
use Polymorph\Sdk\Testing\Data\InMemoryRepository;

uses()->group('sdk-testing');

it('honours the shared Repository contract (in-memory fake)', function (): void {
    // Та же проверка прогоняется против реального flexible-адаптера в
    // tests/Feature/DataPlatform/RepositoryContractTest.php — фейк ≡ реальность.
    RepositoryContract::assertAll(static fn (): InMemoryRepository => new InMemoryRepository);
});
