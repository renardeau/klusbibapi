<?php

use Phinx\Migration\AbstractMigration;
use Illuminate\Database\Capsule\Manager as Capsule;

require_once __DIR__ . '/../../src/env.php';
require_once __DIR__ . '/../../src/settings.php';
/**
 * Custom template for database migration with Illuminate\Database
 * 
 * Default template can be found at https://github.com/robmorgan/phinx/blob/master/src/Phinx/Migration/Migration.template.php.dist
 */
class UpdateToolVisible extends AbstractMigration
{
    /**
     * Up Method.
     *
     * Called when invoking migrate
     *
     * More information on writing migrations is available here:
     * http://docs.phinx.org/en/latest/migrations.html#the-abstractmigration-class
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
	public function up()
	{
		Capsule::schema()->table('tools', function(Illuminate\Database\Schema\Blueprint $table){
			$table->boolean('visible')->default(true);
			$table->string('state', 20)->default("NEW");
		});
	}
    /**
     * Down Method.
     *
     * Called when invoking rollback
     */
	public function down()
	{
		Capsule::schema()->table('tools', function(Illuminate\Database\Schema\Blueprint $table){
			$table->dropColumn('visible');
			$table->dropColumn('state');
		});
	}
}