<?php

// HTE (2026-10-16): each computer's own password, made and changed by the console (app/Hte/Passwords.php).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hte_device_passwords', function (Blueprint $table) {
            $table->id();
            $table->string('rustdesk_id')->unique();
            $table->timestamp('capable_at')->nullable();      // last check-in from an HTE Remote that takes passwords
            $table->unsignedInteger('current_version')->default(0);
            $table->text('current_enc')->nullable();           // Crypt: the newest password, sent until confirmed
            $table->timestamp('issued_at')->nullable();
            $table->string('issued_reason', 64)->nullable();
            $table->unsignedInteger('confirmed_version')->default(0);
            $table->text('confirmed_enc')->nullable();         // Crypt: the one the computer has; the address books carry it
            $table->timestamp('confirmed_at')->nullable();
            $table->json('reach_user_ids')->nullable();        // who could reach it at the last sweep
            $table->timestamps();
        });

        Schema::create('hte_auto_books', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('address_book_id')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hte_auto_books');
        Schema::dropIfExists('hte_device_passwords');
    }
};
