<?php declare(strict_types=1);

namespace Nadybot\User\Modules\ALLIANCE_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\User\Modules\ALLIANCE_MODULE\AllianceController;

class CreateAllianceMembersTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = AllianceController::DB_MEMBERS;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function(Blueprint $table) {
			$table->string("name", 15)->primary();
			$table->integer("org_id");
			$table->string("mode", 7)->nullable();
			$table->integer("logged_off")->nullable()->default(0);
		});
	}
}
