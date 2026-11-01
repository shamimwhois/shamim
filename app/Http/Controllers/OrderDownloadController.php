<?php

namespace App\Http\Controllers;

use App\Actions\Marketplace\PublishRelease;
use App\Enums\OrderStatus;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceProductVersion;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stream the deliverable attached to an entitled order's current release.
 *
 * Three claims have to hold at once, and losing any one of them answers 404
 * rather than 403 because a stranger probing ids should not learn that an order
 * exists, still less that it was paid for:
 *
 * The reader is the buyer the order belongs to. Entitlement is personal — a
 * licence bought by an account is delivered to that account, and no permission a
 * reader separately holds makes an order theirs.
 *
 * The order still entitles. {@see OrderStatus::isEntitling()} is the
 * single definition the storefront already uses to decide whether a listing may
 * be re-downloaded, so a refund or a cancellation revokes the door here too.
 *
 * The release carries bytes. Plenty of listings ship as an install command with
 * no archive behind them, and a release the seller has not attached a file to is
 * a state the buyer screen already explains in words rather than a 404 to debug.
 *
 * The listing is read with trashed models included. An author may withdraw a
 * product after selling it; a paid buyer keeps what was bought, so removal from
 * the catalogue is not revocation of delivery.
 *
 * `download_count` is deliberately untouched. The existing order tests define it
 * as the number of times a listing was bought, not the number of times a file was
 * fetched, so incrementing it here would corrupt a metric the sellers' dashboard
 * reports. Counting deliveries needs its own column and its own decision.
 */
class OrderDownloadController extends Controller
{
    /**
     * Hand the entitled buyer the file for their order's current release.
     */
    public function show(Request $request, MarketplaceOrder $order): StreamedResponse
    {
        abort_unless($order->isOwnedBy($request->user()), 404);

        abort_unless($order->isEntitling(), 404);

        $release = $this->releaseFor($order);

        abort_unless($release !== null && $release->hasDeliverable(), 404);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($release->disk);

        abort_unless($disk->exists($release->path), 404);

        return $disk->response(
            $release->path,
            $release->deliverableName(),
            $this->headers($release),
        );
    }

    /**
     * The release a download would serve, or null when the listing has none.
     */
    private function releaseFor(MarketplaceOrder $order): ?MarketplaceProductVersion
    {
        /** @var MarketplaceProductVersion|null $release */
        $release = MarketplaceProduct::withTrashed()
            ->find($order->marketplace_product_id)
            ?->currentVersion;

        return $release;
    }

    /**
     * Headers that describe the archive as the stored bytes are, not as the
     * browser claimed when it uploaded them.
     *
     * `Content-Type` is the mime read back from the disk, and `Content-Security-Policy`
     * plus `X-Content-Type-Options` fence the response into being a file: a
     * downloadable archive served from the app's own origin must never be
     * renderable as a document that could read this session's cookies.
     *
     * The name is safe to put in `Content-Disposition` because
     * {@see PublishRelease::displayName()} sanitised it
     * on the way in; the fallback is derived from the listing's slug and version.
     *
     * @return array<string, string>
     */
    private function headers(MarketplaceProductVersion $release): array
    {
        return [
            'Content-Type' => $release->mime,
            'Content-Length' => (string) $release->size,
            'Content-Disposition' => 'attachment; filename="'.addcslashes($release->deliverableName(), '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store, max-age=0',
        ];
    }
}
