<?php

declare(strict_types=1);

/**
 * This file is part of the Nexus MCP SDK package.
 *
 * (c) 2026 John Paul E. Balandan, CPA <paulbalandan@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Nexus\Mcp\Server\Resource;

use Nexus\Mcp\Core\Schema\Enum\CacheScope;
use Nexus\Mcp\Core\Schema\Resource\BlobResourceContents;
use Nexus\Mcp\Core\Schema\Resource\TextResourceContents;
use Nexus\Mcp\Core\Schema\Result\InputRequiredResult;
use Nexus\Mcp\Core\Schema\Result\ReadResourceResult;
use Nexus\Mcp\Server\Exception\UnsupportedReturnValueException;

/**
 * Adapts a reflected resource handler's return value to a `ReadResourceResult`.
 *
 * @internal
 */
final class ReflectedResourceResult
{
    /**
     * @param non-empty-string $uri
     */
    public static function adapt(mixed $result, string $uri, \ReflectionMethod $method): InputRequiredResult|ReadResourceResult
    {
        if ($result instanceof ReadResourceResult || $result instanceof InputRequiredResult) {
            return $result;
        }

        $contents = match (true) {
            \is_string($result) => [new TextResourceContents(uri: $uri, text: $result)],
            $result instanceof BlobResourceContents || $result instanceof TextResourceContents => [$result],
            \is_array($result) => self::contentsList($result, $method),
            default => throw self::buildUnsupportedError($method, $result),
        };

        return new ReadResourceResult(contents: $contents, ttlMs: 0, cacheScope: CacheScope::Private);
    }

    /**
     * @param array<array-key, mixed> $result
     *
     * @return list<BlobResourceContents|TextResourceContents>
     */
    private static function contentsList(array $result, \ReflectionMethod $method): array
    {
        $contents = [];

        foreach ($result as $item) {
            if ($item instanceof BlobResourceContents || $item instanceof TextResourceContents) {
                $contents[] = $item;
            }
        }

        if (! array_is_list($result) || [] === $result || \count($contents) !== \count($result)) {
            throw self::buildUnsupportedError($method, $result);
        }

        return $contents;
    }

    private static function buildUnsupportedError(\ReflectionMethod $method, mixed $result): UnsupportedReturnValueException
    {
        return new UnsupportedReturnValueException(
            $method->getDeclaringClass()->getName(),
            $method->getName(),
            \sprintf('a %s, a string, or resource contents', ReadResourceResult::class),
            $result,
        );
    }
}
