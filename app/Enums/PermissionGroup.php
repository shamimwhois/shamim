<?php

namespace App\Enums;

/**
 * The resource area a permission belongs to.
 *
 * A dedicated enum rather than a bare string so that {@see AccountPermission::group()}
 * is a total match and {@see self::label()} cannot fall through on an
 * unrecognised value. Adding a group is a one-line change that the compiler
 * then enforces everywhere it is consumed.
 */
enum PermissionGroup: string
{
    case Content = 'content';
    case Moderation = 'moderation';
    case Selling = 'selling';
    case Administration = 'administration';

    /**
     * Get the display label for the group.
     */
    public function label(): string
    {
        return match ($this) {
            self::Content => __('Content'),
            self::Moderation => __('Moderation'),
            self::Selling => __('Selling'),
            self::Administration => __('Administration'),
        };
    }
}
