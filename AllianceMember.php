<?php declare(strict_types=1);

namespace Nadybot\User\Modules\ALLIANCE_MODULE;

use Nadybot\Modules\GUILD_MODULE\OrgMember;

class AllianceMember extends OrgMember {
	/** The ID of the org this member belongs to */
	public int $org_id;
}