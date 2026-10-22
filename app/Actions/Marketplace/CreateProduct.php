<?php

namespace App\Actions\Marketplace;

use App\Concerns\ResolvesUniqueSlug;
use App\Enums\ProductLicense;
use App\Enums\ProductStatus;
use App\Models\MarketplaceProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Create a new marketplace product listing.
 *
 * This action handles the creation of a new product listing in draft status.
 * It resolves uniqueness of the slug and sets the creator.
 */
class CreateProduct
{
    use ResolvesUniqueSlug;

    /**
     * Create a new product listing.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(User $creator, array $data): MarketplaceProduct
    {
        return DB::transaction(function () use ($creator, $data): MarketplaceProduct {
            $product = new MarketplaceProduct;

            $product->user_id = $creator->id;

            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                throw new InvalidArgumentException(__('A product needs a name before it can be saved.'));
            }

            $slug = $this->resolveUniqueSlug($product, $data, $name);

            $product->fill([
                'marketplace_category_id' => $data['marketplace_category_id'] ?? null,
                'name' => $name,
                'slug' => $slug,
                'tagline' => $data['tagline'] ?? '',
                'description' => $data['description'] ?? '',
                'price_cents' => $data['price_cents'] ?? 0,
                'currency' => $data['currency'] ?? 'USD',
                'license' => $data['license'] ?? ProductLicense::Mit,
                'version' => $data['version'] ?? '1.0.0',
                'framework' => $data['framework'] ?? null,
                'language' => $data['language'] ?? null,
                'install_command' => $data['install_command'] ?? null,
                'demo_url' => $data['demo_url'] ?? null,
                'docs_url' => $data['docs_url'] ?? null,
                'repository_url' => $data['repository_url'] ?? null,
                'tags' => $data['tags'] ?? [],
                'status' => ProductStatus::Draft,
                'is_featured' => false,
            ]);

            $product->save();

            return $product->refresh();
        });
    }
}
