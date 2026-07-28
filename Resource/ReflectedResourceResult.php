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
use Nexus\Mcp\Core\Schema\Resource\ResourceContents;
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
    public static function adapt(mixed $result, string $uri, \ReflectionMethod $method): InputRequiredResult|ReadResourceResult
    {
        if ($result instanceof ReadResourceResult || $result instanceof InputRequiredResult) {
            return $result;
        }

        if (\is_string($result)) {
            return new ReadResourceResult(
                contents: [new TextResourceContents(uri: $uri, text: $result)],
                ttlMs: 0,
                cacheScope: CacheScope::Private,
            );
        }

        if ($result instanceof ResourceContents) {
            return new ReadResourceResult(
                contents: self::contents([$result]),
                ttlMs: 0,
                cacheScope: CacheScope::Private,
            );
        }

        if (\is_array($result)) {
            return new ReadResourceResult(
                contents: self::contentsList($result, $method),
                ttlMs: 0,
                cacheScope: CacheScope::Private,
            );
        }

        throw self::buildUnsupportedError($method, $result);
    }

    /**
     * @param array<array-key, mixed> $result
     *
     * @return list<BlobResourceContents|TextResourceContents>
     */
    private static function contentsList(array $result, \ReflectionMethod $method): array
    {
        $contents = self::contents($result);

        if (! array_is_list($result) || [] === $result || \count($contents) !== \count($result)) {
            throw self::buildUnsupportedError($method, $result);
        }

        return $contents;
    }

    /**
     * @param array<array-key, mixed> $items
     *
     * @return list<BlobResourceContents|TextResourceContents>
     */
    private static function contents(array $items): array
    {
        return array_values(array_filter(
            $items,
            static fn(mixed $item): bool => $item instanceof BlobResourceContents || $item instanceof TextResourceContents,
        ));
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
