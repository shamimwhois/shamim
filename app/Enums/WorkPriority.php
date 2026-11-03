<?php

namespace App\Enums;

/**
 * How much attention a piece of work deserves relative to the rest.
 *
 * Ideas, plans and tasks all carry a priority, and they share this enum rather
 * than each having its own: the meaning of "urgent" does not change between an
 * idea and a task, and three near-identical enums would be three places to keep
 * in step.
 *
 * Priority is nullable on every one of those models. The specification lists it
 * as a field, but an account capturing a thought at 2am should not have to rank
 * it before it has been written down, so "unranked" is a real state and is
 * represented by a null column rather than by a fifth case that means nothing.
 */
enum WorkPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    /**
     * Get the display label for the priority.
     */
    public function label(): string
    {
        return match ($this) {
            self::Low => __('Low'),
            self::Medium => __('Medium'),
            self::High => __('High'),
            self::Urgent => __('Urgent'),
        };
    }

    /**
     * Get the rank of the priority, highest first.
     *
     * Declared as data rather than relied on through enum order so the SQL below
     * and any PHP comparison read the same list.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Urgent => 0,
            self::High => 1,
            self::Medium => 2,
            self::Low => 3,
        };
    }

    /**
     * Get the Flux badge colour token for the priority.
     *
     * A single warm ramp: the colour is the pressure, and only `Urgent` is red.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Low => 'zinc',
            self::Medium => 'sky',
            self::High => 'amber',
            self::Urgent => 'red',
        };
    }

    /**
     * The SQL expression that orders rows by this enum's weights.
     *
     * A `CASE` over the stored strings, because the column holds `urgent` and `low`
     * and alphabetical order would rank them by accident. It is a constant rather
     * than a built expression so `orderByRaw()` receives a compile-time literal: the
     * ordering of a list is not a place where a composed string belongs.
     *
     * A test ties it to {@see self::weight()}, so the two cannot drift apart.
     */
    public const ORDER_BY_SQL = "CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END";

    /**
     * Get the SQL expression that orders rows by this enum's weights.
     *
     * `NULL` sorts last, because an unranked row has no claim on the top of a list.
     *
     * @return literal-string
     */
    public static function orderBySql(): string
    {
        return self::ORDER_BY_SQL;
    }

    /**
     * Get every priority as a value/label pair.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $priority) => ['value' => $priority->value, 'label' => $priority->label()])
            ->values()
            ->toArray();
    }
}
