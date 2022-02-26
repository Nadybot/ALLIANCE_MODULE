<?php declare(strict_types=1);

namespace Nadybot\User\Modules\ALLIANCE_MODULE;

use Nadybot\Core\DBRow;

class AllianceOrg extends DBRow {
	/** ID of the org */
	public int $org_id;

	/** Unix timestamp when the org was added to this alliance */
	public int $added_dt;

	/** Name of the player who added this org to this alliance */
	public string $added_by;
}
