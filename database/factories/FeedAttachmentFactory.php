<?php

namespace Database\Factories;

use App\Models\FeedAttachment;
use App\Models\FeedPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FeedAttachment>
 */
class FeedAttachmentFactory extends Factory
{
    protected $model = FeedAttachment::class;

    /**
     * Define the model's default state.
     *
     * The two states below are the point of this factory. An attachment row is
     * either staged (a `temp_key`, no post) or claimed (a post, no key), and the
     * rows the console, the policy, and `PruneFeedMedia` all read depend on
     * which of the two columns is filled. A default that mixed them — a post and
     * a key — would describe an upload in a state the application never writes.
     *
     * Dimensions and mime are recorded the way `AttachFeedMedia` records them:
     * read back from the disk after the move. A factory therefore has to write
     * plausible values for a real image, because `url()` and the composer both
     * branch on them.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tempKey = (string) Str::uuid();
        $width = fake()->numberBetween(320, 1600);

        return [
            'feed_post_id' => null,
            'user_id' => User::factory(),
            'disk' => 'feed_media',
            'path' => 'temp/'.$tempKey.'/'.Str::random(16).'.jpg',
            'mime' => 'image/jpeg',
            'size' => $width * fake()->numberBetween(200, 900),
            'width' => $width,
            'height' => (int) round($width / fake()->randomElement([1, 2]) * fake()->randomFloat(2, 0.5, 1)),
            'alt_text' => null,
            'sort_order' => 0,
            'temp_key' => $tempKey,
        ];
    }

    /**
     * An upload in flight: staged under a key, not yet owned by any post.
     */
    public function staged(?string $tempKey = null): static
    {
        return $this->state(function (array $attributes) use ($tempKey): array {
            $key = $tempKey ?? (string) Str::uuid();

            return [
                'feed_post_id' => null,
                'temp_key' => $key,
                'path' => 'temp/'.$key.'/'.Str::random(16).'.jpg',
            ];
        });
    }

    /**
     * A finished attachment: bound to a post, its temp key retired.
     */
    public function claimed(?FeedPost $post = null, int $sortOrder = 0): static
    {
        return $this->state(function (array $attributes) use ($post, $sortOrder): array {
            $target = $post ?? FeedPost::factory()->create();

            return [
                'feed_post_id' => $target->id,
                'temp_key' => null,
                'sort_order' => $sortOrder,
                'path' => 'posts/'.$target->id.'/'.Str::random(16).'.jpg',
            ];
        });
    }

    /**
     * An attached PNG, for the code paths that branch on the mime.
     */
    public function png(): static
    {
        return $this->state(fn (array $attributes) => [
            'mime' => 'image/png',
            'path' => preg_replace('/\.jpg$/', '.png', (string) $attributes['path']),
        ]);
    }

    /**
     * An attachment with no recorded dimensions, which is how a non-image that
     * was accepted by the disk lands in the table.
     */
    public function withoutDimensions(): static
    {
        return $this->state(fn (array $attributes) => [
            'mime' => 'application/pdf',
            'width' => null,
            'height' => null,
            'path' => preg_replace('/\.jpg$/', '.pdf', (string) $attributes['path']),
        ]);
    }
}
