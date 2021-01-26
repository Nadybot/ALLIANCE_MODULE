<?php declare(strict_types=1);

namespace Nadybot\Modules;

use Nadybot\Core\AccessManager;
use Nadybot\User\Modules\ALLIANCE_MODULE\AllianceController;

/**
 * The AccessLevel class provides functionality for checking a player's access level.
 *
 * @Instance(overwrite=true, value="accessmanager")
 */
class AllianceAccessManager extends AccessManager {

	/** @Inject */
	public AllianceController $allianceController;

	/**
	 * Returns the access level of $sender, ignoring guild admin and inheriting access level from main
	 */
	public function getSingleAccessLevel(string $sender): string {
		$accessLevel = parent::getSingleAccessLevel($sender);
		if ($accessLevel !== "all") {
			return $accessLevel;
		}
		if (isset($this->allianceController->allianceMembers[$sender])) {
			return "guild";
		}
		return $accessLevel;
	}
}
