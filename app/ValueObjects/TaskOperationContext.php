<?php

namespace App\ValueObjects;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class TaskOperationContext
{
    public const SOURCE_WEB = 'web';

    public const SOURCE_SYSTEM = 'system';

    public const SOURCE_CONSOLE = 'console';

    public const SOURCE_TEST = 'test';

    public ?int $actorId;

    public string $source;

    public ?string $correlationId;

    public CarbonImmutable $occurredAt;

    public ?int $expectedVersion;

    private function __construct(
        ?int $actorId,
        string $source,
        ?string $correlationId = null,
        ?CarbonInterface $occurredAt = null,
        ?int $expectedVersion = null,
    ) {
        if (! preg_match('/^[a-z][a-z0-9._-]{0,31}$/', $source)) {
            throw new InvalidArgumentException('Task operation source must be a valid string of at most 32 characters.');
        }

        if ($correlationId !== null && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/', $correlationId)) {
            throw new InvalidArgumentException('Task operation correlation ID is invalid.');
        }

        $this->actorId = $actorId;
        $this->source = $source;
        $this->correlationId = $correlationId;
        $this->occurredAt = CarbonImmutable::instance($occurredAt ?? now());
        $this->expectedVersion = $expectedVersion;
    }

    public static function web(User $actor, ?string $correlationId = null, ?CarbonInterface $occurredAt = null, ?int $expectedVersion = null): self
    {
        return new self($actor->id, self::SOURCE_WEB, $correlationId, $occurredAt, $expectedVersion);
    }

    public static function system(?int $actorId = null, ?string $correlationId = null, ?CarbonInterface $occurredAt = null, ?int $expectedVersion = null): self
    {
        return new self($actorId, self::SOURCE_SYSTEM, $correlationId, $occurredAt, $expectedVersion);
    }

    public static function console(?int $actorId = null, ?string $correlationId = null, ?CarbonInterface $occurredAt = null, ?int $expectedVersion = null): self
    {
        return new self($actorId, self::SOURCE_CONSOLE, $correlationId, $occurredAt, $expectedVersion);
    }

    public static function test(?int $actorId = null, ?string $correlationId = null, ?CarbonInterface $occurredAt = null, ?int $expectedVersion = null): self
    {
        return new self($actorId, self::SOURCE_TEST, $correlationId, $occurredAt, $expectedVersion);
    }
}
