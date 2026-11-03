<?php

namespace App\Actions\About;

use App\Models\AboutSection;

/**
 * Remove an About page section.
 *
 * The items' `about_section_id` foreign key cascades at the database level,
 * so deleting the section takes its whole content with it — no separate item
 * sweep is required here.
 */
class DeleteAboutSection
{
    /**
     * Delete the section and, by cascade, every row inside it.
     */
    public function handle(AboutSection $section): void
    {
        $section->delete();
    }
}
