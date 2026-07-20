<?php

declare(strict_types=1);

namespace Polymorph\Sdk\Testing\Logging;

use Polymorph\Sdk\Logging\Redactor;

/**
 * Тестовый {@see Redactor}: маскирует значения у ключей, чьё имя содержит
 * token/secret/password/key/authorization (без config/regex-движка ядра — это
 * фейк). Достаточно, чтобы провайдеры расширений с audit/redaction резолвились в
 * unit-тестах; точность маскирования проверяется против реального PayloadRedactor.
 */
final class FakeRedactor implements Redactor
{
    public function redact(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->redact($value);

                continue;
            }
            $out[$key] = is_string($key) && $this->isSensitive($key) ? self::REDACTED : $value;
        }

        return $out;
    }

    public function redactString(string $value): string
    {
        return $value;
    }

    private function isSensitive(string $key): bool
    {
        return (bool) preg_match('/token|secret|password|api[_-]?key|authorization/i', $key);
    }
}
