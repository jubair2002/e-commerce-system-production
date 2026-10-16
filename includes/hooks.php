<?php
/**
 * Simple hook event bus to decouple subsystems.
 */

// Global hook registry
$GLOBALS['_APP_HOOKS'] = $GLOBALS['_APP_HOOKS'] ?? [];

/**
 * Register a listener for an event.
 *
 * Supported standard events:
 * - order.placed
 * - order.status_changed
 * - stock.low
 * - product.viewed
 * - cart.added
 * - cart.removed
 * - checkout.started
 */
function hook_on(string $event, callable $fn): void
{
    global $_APP_HOOKS;
    $_APP_HOOKS[$event][] = $fn;
}

/**
 * Alias for hook_on
 */
function hook_register(string $event, callable $fn): void
{
    hook_on($event, $fn);
}

/**
 * Fire an event with payload.
 * Every listener runs inside a try/catch block so failures never interrupt core flows.
 */
function hook_fire(string $event, array $payload = []): void
{
    global $_APP_HOOKS;
    if (empty($_APP_HOOKS[$event])) {
        return;
    }
    foreach ($_APP_HOOKS[$event] as $fn) {
        try {
            $fn($payload);
        } catch (Throwable $e) {
            error_log("Hook error on event [{$event}]: " . $e->getMessage());
        }
    }
}

/**
 * Reset hooks (primarily for testing).
 */
function hook_clear(?string $event = null): void
{
    global $_APP_HOOKS;
    if ($event !== null) {
        unset($_APP_HOOKS[$event]);
    } else {
        $_APP_HOOKS = [];
    }
}
