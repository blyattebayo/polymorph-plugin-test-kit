<?php

declare(strict_types=1);

namespace Polymorph\Sdk\Testing\Validation;

use Polymorph\Sdk\Validation\EmailConstraint;
use Polymorph\Sdk\Validation\PasswordConstraint;
use Polymorph\Sdk\Validation\PatternConstraint;
use Polymorph\Sdk\Validation\ValidationConstraints;

/**
 * Фейковые правила валидации для тестов: разумные дефолты, совместимые с
 * грамматикой ядра (slug и т.п.), без чтения config хоста.
 */
final class FakeValidationConstraints implements ValidationConstraints
{
    public function password(): PasswordConstraint
    {
        return new PasswordConstraint(8, 72);
    }

    public function email(): EmailConstraint
    {
        return new EmailConstraint(254);
    }

    public function slug(): PatternConstraint
    {
        return new PatternConstraint('^[a-z][a-z0-9_-]*$', 64);
    }

    public function aclAction(): PatternConstraint
    {
        return new PatternConstraint('^[a-z][a-z0-9_]*$', 64);
    }

    public function roleCode(): PatternConstraint
    {
        return new PatternConstraint('^[a-z][a-z0-9_.]*$', 64);
    }
}
