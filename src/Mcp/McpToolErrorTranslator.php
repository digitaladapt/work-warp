<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Docker\DockerException;
use App\Exec\InvalidExecRequest;
use App\Exec\SlotUnavailable;
use App\Exec\StdinUnsupported;
use App\Session\InvalidSessionName;
use App\Session\NoSuchSession;
use App\Stack\InvalidStackRequest;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RegistryException;
use Mcp\Exception\ToolCallException;
use Override;
use Throwable;

/**
 * Keeps the broker's own refusal messages in front of the caller.
 *
 * This exists because of how the SDK classifies a thrown exception, and the
 * difference is worth understanding before changing any of it:
 *
 *  - `ToolCallException` → `CallToolResult(isError: true)` carrying the
 *    message. The model reads it and can correct itself.
 *  - anything else → a generic `-32603 "Error while executing tool"`. The real
 *    message is logged server-side and **discarded**.
 *
 * Every refusal this broker raises is meant to be read: "there is no session
 * named \"refactor\"; create it with POST /v1/sessions first", "every exec slot
 * is busy; the broker does not queue", "cmd[0] must not be empty". Under the
 * SDK's default handling those would all become "Error while executing tool",
 * which is a real regression — the caller's one useful clue would move to a log
 * file nobody is reading.
 *
 * Rather than have each tool catch and re-throw, the translation happens once
 * here, at the single point every handler passes through. It is deliberately
 * conservative:
 *
 *  - `ToolCallException`, `RegistryException` and `InvalidArgumentException`
 *    already carry the right meaning and pass through untouched.
 *  - Only failures raised while a tool is actually being invoked are
 *    translated (`_session` is in the argument bag solely for tool calls), so
 *    resource reads keep their own error semantics.
 *  - A `DockerException`'s message already carries the daemon's words — the
 *    whole point of that hierarchy — so translating it loses nothing.
 *
 * The list of "refusals meant for a caller" is the broker's own vocabulary:
 * a session that is not there, a request that cannot be expressed, a budget
 * that is full, and the daemon saying no. Everything else stays an internal
 * error, because it is one.
 */
final class McpToolErrorTranslator implements ReferenceHandlerInterface
{
    public function __construct(
        private readonly ReferenceHandlerInterface $inner,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Override]
    public function handle(ElementReference $reference, array $arguments): mixed
    {
        try {
            return $this->inner->handle($reference, $arguments);
        } catch (RegistryException|InvalidArgumentException $e) {
            // Already the shape the SDK renders, and already carrying a
            // message meant for the client.
            throw $e;
        } catch (Throwable $e) {
            // A tool that already threw `ToolCallException` needs no help — it
            // is the shape `CallToolHandler` renders with `isError: true`. It
            // is tested here rather than listed in the catch above because the
            // interface declares only `RegistryException` and
            // `InvalidArgumentException`; a `ToolCallException` reaches us
            // through a tool's own throw, which no signature advertises.
            if ($e instanceof ToolCallException) {
                throw $e;
            }

            if (!isset($arguments['_session'])) {
                // Not a tool call: let it surface as-is rather than
                // mislabelling it a tool failure.
                throw $e;
            }

            if (self::isCallerFacing($e)) {
                throw new ToolCallException($e->getMessage(), 0, $e);
            }

            throw $e;
        }
    }

    /**
     * The failures whose message is written for whoever called, not for an
     * operator reading a log. Anything not listed keeps its internal-error
     * classification on purpose — a bug dressed up as a caller's mistake is
     * worse than a bug.
     */
    private static function isCallerFacing(Throwable $e): bool
    {
        return $e instanceof NoSuchSession
            || $e instanceof InvalidSessionName
            || $e instanceof InvalidExecRequest
            || $e instanceof InvalidStackRequest
            || $e instanceof SlotUnavailable
            || $e instanceof StdinUnsupported
            || $e instanceof DockerException;
    }
}
