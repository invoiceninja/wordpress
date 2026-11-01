<?php
// Action Scheduler functions used by the plugin (loaded at runtime from vendor/woocommerce/action-scheduler).
/** @param array<mixed> $args */
function as_enqueue_async_action( string $hook, array $args = array(), string $group = '', bool $unique = false, int $priority = 10 ): int { return 0; }
/** @param array<mixed> $args */
function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '', bool $unique = false, int $priority = 10 ): int { return 0; }
/** @param array<mixed> $args */
function as_schedule_recurring_action( int $timestamp, int $interval_in_seconds, string $hook, array $args = array(), string $group = '', bool $unique = false, int $priority = 10 ): int { return 0; }
/** @param array<mixed>|null $args */
function as_has_scheduled_action( string $hook, ?array $args = null, string $group = '' ): bool { return false; }
/** @param array<mixed> $args */
function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void {}
/**
 * @param array<string, mixed> $args
 * @return array<int, mixed>
 */
function as_get_scheduled_actions( array $args = array(), string $return_format = OBJECT ): array { return array(); }
function WC(): object { return new stdClass(); }
