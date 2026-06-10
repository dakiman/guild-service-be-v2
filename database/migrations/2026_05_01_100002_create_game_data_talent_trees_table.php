<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_data_talent_trees', function (Blueprint $table) {
            $table->unsignedInteger('tree_id');
            $table->unsignedInteger('spec_id');
            $table->string('name', 200);
            $table->jsonb('tree');
            $table->timestamp('synced_at')->useCurrent();
            $table->timestamps();

            $table->primary(['tree_id', 'spec_id']);
            $table->index('spec_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_data_talent_trees');
    }
};
