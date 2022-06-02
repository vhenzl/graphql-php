<?php declare(strict_types=1);

namespace GraphQL\Error;

use const E_USER_WARNING;
use GraphQL\Exception\InvalidArgument;
use function is_int;
use function trigger_error;

/**
 * Encapsulates warnings produced by the library.
 *
 * Warnings can be suppressed (individually or all) if required.
 * Also, it is possible to override warning handler (which is **trigger_error()** by default).
 *
 * @phpstan-type WarningHandler callable(string $errorMessage, int $warningId, ?int $messageLevel): void
 *
 * @see https://github.com/vimeo/psalm/issues/7527
 * @psalm-type WarningHandler callable(string, int, int|null): void
 */
final class Warning
{
    public const NONE = 0;
    public const WARNING_ASSIGN = 2;
    public const WARNING_CONFIG = 4;
    public const WARNING_FULL_SCHEMA_SCAN = 8;
    public const WARNING_CONFIG_DEPRECATION = 16;
    public const WARNING_NOT_A_TYPE = 32;
    public const ALL = 63;

    private static int $enableWarnings = self::ALL;

    /** @var array<int, true> */
    private static array $warned = [];

    /**
     * @var callable|null
     * @phpstan-var WarningHandler|null
     */
    private static $warningHandler;

    /**
     * Sets warning handler which can intercept all system warnings.
     * When not set, trigger_error() is used to notify about warnings.
     *
     * @phpstan-param WarningHandler|null $warningHandler
     *
     * @api
     */
    public static function setWarningHandler(?callable $warningHandler = null): void
    {
        self::$warningHandler = $warningHandler;
    }

    /**
     * Suppress warning by id (has no effect when custom warning handler is set).
     *
     * @param bool|int $suppress
     *
     * @example Warning::suppress(Warning::WARNING_NOT_A_TYPE) suppress a specific warning
     * @example Warning::suppress(true) suppresses all warnings
     * @example Warning::suppress(false) enables all warnings
     *
     * @api
     */
    public static function suppress($suppress = true): void
    {
        if (true === $suppress) {
            self::$enableWarnings = 0;
        } elseif (false === $suppress) {
            self::$enableWarnings = self::ALL;
        // @phpstan-ignore-next-line necessary until we can use proper unions
        } elseif (is_int($suppress)) {
            self::$enableWarnings &= ~$suppress;
        } else {
            throw InvalidArgument::fromExpectedTypeAndArgument('bool|int', $suppress);
        }
    }

    /**
     * Re-enable previously suppressed warning by id (has no effect when custom warning handler is set).
     *
     * @param bool|int $enable
     *
     * @example Warning::suppress(Warning::WARNING_NOT_A_TYPE) re-enables a specific warning
     * @example Warning::suppress(true) re-enables all warnings
     * @example Warning::suppress(false) suppresses all warnings
     *
     * @api
     */
    public static function enable($enable = true): void
    {
        if (true === $enable) {
            self::$enableWarnings = self::ALL;
        } elseif (false === $enable) {
            self::$enableWarnings = 0;
        // @phpstan-ignore-next-line necessary until we can use proper unions
        } elseif (is_int($enable)) {
            self::$enableWarnings |= $enable;
        } else {
            throw InvalidArgument::fromExpectedTypeAndArgument('bool|int', $enable);
        }
    }

    public static function warnOnce(string $errorMessage, int $warningId, ?int $messageLevel = null): void
    {
        $messageLevel ??= E_USER_WARNING;

        if (null !== self::$warningHandler) {
            (self::$warningHandler)($errorMessage, $warningId, $messageLevel);
        } elseif ((self::$enableWarnings & $warningId) > 0 && ! isset(self::$warned[$warningId])) {
            self::$warned[$warningId] = true;
            trigger_error($errorMessage, $messageLevel);
        }
    }

    public static function warn(string $errorMessage, int $warningId, ?int $messageLevel = null): void
    {
        $messageLevel ??= E_USER_WARNING;

        if (null !== self::$warningHandler) {
            (self::$warningHandler)($errorMessage, $warningId, $messageLevel);
        } elseif ((self::$enableWarnings & $warningId) > 0) {
            trigger_error($errorMessage, $messageLevel);
        }
    }
}
