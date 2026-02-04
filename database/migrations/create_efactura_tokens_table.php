<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('efactura.tokens_table', 'efactura_tokens'), function (Blueprint $table) {
            $table->id();
            $table->string('cif')->unique();
            $table->text('access_token');
            $table->timestamp('access_token_expires_at');
            $table->text('refresh_token');
            $table->timestamp('refresh_token_expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('efactura.tokens_table', 'efactura_tokens'));
    }
};
