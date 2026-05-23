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

namespace Nexus\Mcp\Server\Validation;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

/**
 * Default `SchemaValidatorInterface` backed by opis/json-schema.
 */
final readonly class OpisSchemaValidator implements SchemaValidatorInterface
{
    private Validator $validator;

    public function __construct()
    {
        $this->validator = new Validator();
    }

    #[\Override]
    public function validate(mixed $data, array $schema): array
    {
        $error = $this->validator->validate(Helper::toJSON($data), (object) Helper::toJSON($schema))->error();

        if (null === $error) {
            return [];
        }

        /** @var list<string> $messages */
        $messages = new ErrorFormatter()->formatFlat($error);

        return $messages;
    }
}
