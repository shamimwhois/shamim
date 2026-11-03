<?php

namespace App\Actions\Marketplace;

use App\Concerns\ResolvesUniqueSlug;
use App\Enums\ProductStatus;
use App\Models\MarketplaceProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Update an existing marketplace product listing.
 *
 * Only the owner may update, and only while the product is in Draft status.
 * The slug is re-resolved if the name changes.
 */
class UpdateProduct
{
    use ResolvesUniqueSlug;

    /**
     * Update the product listing.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(User $user, MarketplaceProduct $product, array $data): MarketplaceProduct
    {
        return DB::transaction(function () use ($user, $product, $data): MarketplaceProduct {
            if (! $product->isOwnedBy($user)) {
                throw new InvalidArgumentException(__('You may not edit this product.'));
            }

            if ($product->status !== ProductStatus::Draft) {
                throw new InvalidArgumentException(__('Only draft products can be edited.'));
            }

            $name = trim((string) ($data['name'] ?? $product->name));
            if ($name === '') {
                throw new InvalidArgumentException(__('A product needs a name before it can be saved.'));
            }

            // Re-resolve slug if name changed
            $slug = $name !== $product->name
                ? $this->resolveUniqueSlug($product, $data, $name)
                : $product->slug;

            $product->fill([
                'marketplace_category_id' => $data['marketplace_category_id'] ?? $product->marketplace_category_id,
                'name' => $name,
                'slug' => $slug,
                'tagline' => $data['tagline'] ?? $product->tagline,
                'description' => $data['description'] ?? $product->description,
                'price_cents' => $data['price_cents'] ?? $product->price_cents,
                'currency' => $data['currency'] ?? $product->currency,
                'license' => $data['license'] ?? $product->license,
                'version' => $data['version'] ?? $product->version,
                'framework' => $data['framework'] ?? $product->framework,
                'language' => $data['language'] ?? $product->language,
                'install_command' => $data['install_command'] ?? $product->install_command,
                'demo_url' => $data['demo_url'] ?? $product->demo_url,
                'docs_url' => $data['docs_url'] ?? $product->docs_url,
                'repository_url' => $data['repository_url'] ?? $product->repository_url,
                'tags' => $data['tags'] ?? $product->tags,
            ]);

            $product->save();

            return $product->refresh();
        });
    }
}
