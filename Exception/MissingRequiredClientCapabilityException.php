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

namespace Nexus\Mcp\Server\Exception;

use Nexus\Mcp\Core\Exception\AbstractJsonRpcProtocolException;
use Nexus\Mcp\Core\Schema\ClientCapabilities;
use Nexus\Mcp\Core\Schema\Enum\ProtocolErrorCode;
use Nexus\Mcp\Core\Schema\RequestId;

/**
 * Thrown when serving a request would rely on a client capability absent from the request's
 * `io.modelcontextprotocol/clientCapabilities`.
 */
final class MissingRequiredClientCapabilityException extends AbstractJsonRpcProtocolException
{
    public function __construct(
        public readonly ClientCapabilities $requiredCapabilities,
        ?RequestId $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $requestId,
            \sprintf(
                'This request requires client capabilities the client did not declare: %s.',
                implode(', ', self::describeCapabilities($requiredCapabilities->toArray())),
            ),
            $previous,
            errorData: ['requiredCapabilities' => $requiredCapabilities->toArray()],
        );
    }

    #[\Override]
    public static function getErrorCode(): ProtocolErrorCode
    {
        return ProtocolErrorCode::MissingRequiredClientCapability;
    }

    /**
     * Renders each required slot, naming the nested members of a map-valued
     * slot (`extensions.com.example/feature`) so the refusal says which one
     * is missing.
     *
     * @param array<string, mixed> $capabilities
     *
     * @return list<string>
     */
    private static function describeCapabilities(array $capabilities): array
    {
        $described = [];

        foreach ($capabilities as $slot => $members) {
            if (\is_array($members) && [] !== $members && array_all($members, static fn(mixed $value, mixed $key): bool => \is_string($key))) {
                foreach (array_keys($members) as $member) {
                    $described[] = \sprintf('%s.%s', $slot, $member);
                }

                continue;
            }

            $described[] = $slot;
        }

        return $described;
    }
}
