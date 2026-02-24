<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_user', function (Blueprint $table) {
            $table->foreignId('room_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('character_name')->nullable();
            $table->text('character_description')->nullable();
            $table->string('character_class')->nullable();
            $table->unsignedTinyInteger('strength')->default(10);
            $table->unsignedTinyInteger('dexterity')->default(10);
            $table->unsignedTinyInteger('constitution')->default(10);
            $table->unsignedTinyInteger('intelligence')->default(10);
            $table->unsignedTinyInteger('wisdom')->default(10);
            $table->unsignedTinyInteger('charisma')->default(10);
            $table->integer('max_hp')->default(30);
            $table->integer('current_hp')->default(30);
            $table->unsignedTinyInteger('armor_class')->default(10);
            $table->json('abilities')->nullable();
            $table->boolean('is_ready')->default(false);
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();
            $table->primary(['room_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_user');
    }
};