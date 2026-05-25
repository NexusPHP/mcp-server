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

use Nexus\Mcp\Core\Exception\McpExceptionInterface;

/**
 * Thrown when a source passed to `ServerBuilder::register()` carries no discoverable attribute.
 */
final class MissingDiscoveryAttributeException extends \LogicException implements McpExceptionInterface
{
    /**
     * @param class-string $source
     */
    public function __construct(string $source)
    {
        parent::__construct(\sprintf(
            'The registered source "%s" declares no #[AsServer] and no #[AsTool], #[AsPrompt], #[AsResource], or #[AsResourceTemplate] method.',
            $source,
        ));
    }
}
