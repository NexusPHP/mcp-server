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

namespace Nexus\Mcp\Server\Transport\Http\Middleware;

use Nexus\Assert\Assert;
use Nexus\Mcp\Core\Http\HttpStatus;
use Nexus\Mcp\Core\Schema\Error\InvalidRequestError;
use Nexus\Mcp\Core\Schema\JsonRpc\JsonRpcErrorResponse;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rejects a request whose body exceeds a configured byte cap before it reaches the transport.
 *
 * An oversized body is answered with an id-less JSON-RPC error on HTTP 413, sparing the transport the cost of
 * stringifying and parsing it. The cap is measured against the buffered body size. A body whose size cannot be
 * determined passes through, leaving a streaming cap to the HTTP server.
 */
final readonly class RequestBodySizeLimitMiddleware implements MiddlewareInterface
{
    /**
     * @param int<0, max> $maxBytes Maximum permitted request body size in bytes
     */
    public function __construct(
        private int $maxBytes,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
        Assert::that($maxBytes)->isNaturalInt('The maximum request body size must be a non-negative integer, {value} given.');
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $size = $request->getBody()->getSize();

        if (null !== $size && $size > $this->maxBytes) {
            return $this->reject();
        }

        return $handler->handle($request);
    }

    private function reject(): ResponseInterface
    {
        $envelope = new JsonRpcErrorResponse(
            id: null,
            error: new InvalidRequestError(message: 'The request body exceeds the permitted size.'),
        );

        return $this->responseFactory->createResponse(HttpStatus::ContentTooLarge->value)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($envelope, \JSON_THROW_ON_ERROR)))
        ;
    }
}
