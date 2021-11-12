<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ChangePreHeightMinToCandidateInformationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('candidate_information', function (Blueprint $table) {
            $table->string('pre_height_min',20)->default(91.44)->change();
            $table->string('pre_height_max',20)->default(304.8)->change();
            $table->string('pre_partner_religions',255)->default(1)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {

    }
}
