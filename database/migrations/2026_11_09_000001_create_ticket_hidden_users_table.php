<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_ticket_hidden_user', function (Blueprint $table) {
            $table->integer('user_id')->primary();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_ticket_hidden_user');
    }
};
