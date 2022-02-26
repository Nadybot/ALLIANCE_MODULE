<?php declare(strict_types=1);

namespace Nadybot\User\Modules\ALLIANCE_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\DB;
use Nadybot\Core\LoggerWrapper;
use Nadybot\Core\SchemaMigration;
use Nadybot\User\Modules\ALLIANCE_MODULE\AllianceController;

class CreateAllianceOrgsTable implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$table = AllianceController::DB_ORGS;
		if ($db->schema()->hasTable($table)) {
			return;
		}
		$db->schema()->create($table, function(Blueprint $table) {
			$table->integer("org_id")->primary();
			$table->integer("added_dt");
			$table->string("added_by", 15);
		});
	}
}
