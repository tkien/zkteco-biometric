<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('biometric_employees', function (Blueprint $table) {
            $table->id();
            $table->boolean('force_biometric_clockin')->default(true);
            $table->string('biometric_employee_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('card_number')->nullable();
            $table->boolean('has_fingerprint')->default(false);
            $table->string('fingerprint_id')->nullable();
            $table->longText('fingerprint_template')->nullable();
            $table->boolean('has_photo')->default(false);
            $table->longText('photo')->nullable();
            $table->boolean('has_face')->default(false);
            $table->longText('face_template')->nullable();
            $table->string('face_template_major_ver')->nullable();
            $table->string('clock_in_method')->nullable();
            $table->timestamps();

            $table->index('biometric_employee_id');
            $table->index('user_id');
            $table->index('card_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biometric_employees');
    }
};
