<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('mixpost_post_versions', 'options')) {
            return;
        }

        Schema::table('mixpost_post_versions', function (Blueprint $table) {
            $table->json('options')->nullable()->after('content');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('mixpost_post_versions', function (Blueprint $table) {
            $table->dropColumn('options');
        });
    }
};
