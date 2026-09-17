<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_reads', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('key', 64);
            $table->timestamp('read_at')->nullable();

            $table->primary(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_reads');
    }
};
