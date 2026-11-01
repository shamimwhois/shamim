<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The deliverable file carried by a release.
 *
 * Every column is nullable, because a release with no file is a legitimate thing
 * to publish: a listing whose install command is `composer require …` has nothing
 * to stream, and the storefront says so rather than showing a broken download.
 *
 * `disk` and `path` follow the media table — private disk, signed route, mime read
 * back from storage rather than believed from the request. `original_name` is the
 * name the buyer's browser will be offered in Content-Disposition, so it is
 * written already sanitised: a filename is attacker-chosen text, and the quote
 * marks in it are a header-injection vector if it is stored raw.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('marketplace_product_versions', function (Blueprint $table) {
            $table->string('disk')->nullable()->after('release_notes');
            $table->string('path')->nullable()->after('disk');
            $table->string('mime')->nullable()->after('path');
            $table->unsignedBigInteger('size')->nullable()->after('mime');
            $table->string('original_name')->nullable()->after('size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketplace_product_versions', function (Blueprint $table) {
            $table->dropColumn([
                'disk',
                'path',
                'mime',
                'size',
                'original_name',
            ]);
        });
    }
};
