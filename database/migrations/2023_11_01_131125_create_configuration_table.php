<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateConfigurationTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
	public function up()
	{
		Schema::create('configurations', function (Blueprint $table) {
			$table->id();
			$table->string('project_name');
			$table->text('lightspeed_api_key');
			$table->text('lightspeed_api_secret');
			$table->text('lightspeed_api_url');
			$table->text('customerlabs_api_key');
			$table->text('customerlabs_endpoint');
			$table->boolean('is_active')->default(1);
			$table->timestamps();
			$table->unique('project_name');
		});
	}


    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('configuration');
    }
}
