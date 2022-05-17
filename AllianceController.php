<?php declare(strict_types=1);

namespace Nadybot\User\Modules\ALLIANCE_MODULE;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessLevelProvider,
	AccessManager,
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	DB,
	Event,
	EventManager,
	LoggerWrapper,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\Guild,
	Modules\PLAYER_LOOKUP\GuildManager,
	Nadybot,
	ParamClass\PNonNumber,
	ParamClass\PRemove,
	SQLException,
	Text,
	Util,
};
use Nadybot\Modules\ORGLIST_MODULE\{
	FindOrgController,
	Organization,
};
use stdClass;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command:     "alliance",
		accessLevel: "mod",
		description: "Manage orgs of the alliance",
	)
]
class AllianceController extends ModuleInstance implements AccessLevelProvider {
	public const DB_MEMBERS = "alliance_members_<myname>";
	public const DB_ORGS = "alliance_orgs_<myname>";

	#[NCA\Inject]
	public FindOrgController $findOrgController;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public GuildManager $guildManager;

	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** Which bot rank will all alliance members get */
	#[NCA\Setting\Rank(accessLevel: "superadmin")]
	public string $allianceMappedRank = "guild";

	/**
	 * The rank for each member of this bot's alliance
	 * [(string)name => (int)rank]
	 * @var array<string,int>
	 */
	public array $allianceMembers = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->accessManager->registerProvider($this);
		$query = $this->db->table(static::DB_MEMBERS, "a")
			->leftJoin("players as p", function(JoinClause $join) {
				$join->on("a.name", "p.name")
					->where("p.dimension", $this->db->getDim())
					->on("p.guild_id", "a.org_id");
			})
			->where("a.mode", "!=", "del")
			->select("a.name");
		$this->allianceMembers = $query->selectRaw(
			"COALESCE(" . $query->grammar->wrap("p.guild_rank_id") . ", 6)".
			$query->as("guild_rank_id")
		)->get()
		->reduce(function (array $carry, stdClass $row) {
			$carry[(string)$row->name] = (int)$row->guild_rank_id;
			return $carry;
		}, []);
	}

	/**
	 * Make everyone in the alliance a "guild" member
	 */
	public function getSingleAccessLevel(string $sender): ?string {
		if (isset($this->allianceMembers[$sender])) {
			return $this->allianceMappedRank;
		}
		return null;
	}

	#[NCA\Event(
		name: "timer(24hrs)",
		description: "Download all alliance org information"
	)]
	public function downloadOrgRostersEvent(Event $eventObj): void {
		$this->updateOrgRosters();
	}

	/**
	 * Do a manual alliance roster update
	 */
	#[NCA\HandlesCommand("alliance")]
	public function allianceUpdateCommand(
		CmdContext $context,
		#[NCA\Str("update")] string $action
	): void {
		$context->reply("Starting Alliance Roster update");
		$this->updateOrgRosters([$context, "reply"], "Finished Alliance Roster update");
	}

	public function updateOrgRosters(?callable $callback=null, mixed ...$args): void {
		$this->logger->notice("Starting Alliance Roster update");

		/** @var Collection<AllianceOrg> */
		$orgs = $this->db->table(static::DB_ORGS)->asObj(AllianceOrg::class);

		$i = 0;
		foreach ($orgs as $org) {
			$i++;
			// Get the org info
			$this->guildManager->getByIdAsync(
				$org->org_id,
				null,
				true,
				[$this, "updateRosterForGuild"],
				function() use (&$i, $callback, $args): void {
					if (--$i === 0) {
						if (isset($callback)) {
							$callback(...$args);
						}
						$this->logger->notice("Finished Alliance Roster update");
					}
				}
			);
		}
	}

	public function updateRosterForGuild(?Guild $org, ?callable $callback, mixed ...$args): void {
		// Check if JSON file was downloaded properly
		if ($org === null) {
			$this->logger->error("Error downloading the guild roster JSON file");
			return;
		}

		if (count($org->members) === 0) {
			$this->logger->error("The organisation {$org->orgname} has no members. Not changing its roster");
			return;
		}

		// Save the current org_members table in a var
		/** @var array<string,AllianceMember> */
		$dbEntries = $this->db->table(static::DB_MEMBERS)
			->asObj(AllianceMember::class)
			->keyBy("name")
			->toArray();

		$this->db->beginTransaction();

		// Going through each member of the org and add or update his/her status
		foreach ($org->members as $member) {
			// don't do anything if $member is the bot itself
			if (strtolower($member->name) === strtolower($this->chatBot->char->name)) {
				continue;
			}

			// If there's already data about the character just update them
			if (isset($dbEntries[$member->name])) {
				if ($dbEntries[$member->name]->mode === "del") {
					// members who are not on notify should not be on the buddy list but should remain in the database
					$this->buddylistManager->remove($member->name, 'alliance');
					unset($this->allianceMembers[$member->name]);
				} elseif (isset($member->guild_rank_id)) {
					// add org members who are on notify to buddy list
					$this->buddylistManager->add($member->name, 'alliance');
					$this->allianceMembers[$member->name] = $member->guild_rank_id;

					// if member was added to notify list manually, switch mode to org and let guild roster update from now on
					if ($dbEntries[$member->name]->mode === "add") {
						$this->db->table(static::DB_MEMBERS)
							->where("name", $member->name)
							->update(["mode" => "org"]);
					}
				}
			// Else insert their data
			} elseif (isset($member->guild_rank_id)) {
				// add new org members to buddy list
				$this->buddylistManager->add($member->name, 'alliance');
				$this->allianceMembers[$member->name] = $member->guild_rank_id;

				$this->db->table(static::DB_MEMBERS)
					->insert([
						"org_id" => $org->guild_id,
						"name" => $member->name,
						"mode" => "org",
					]);
			}
			unset($dbEntries[$member->name]);
		}

		$this->db->commit();

		// remove buddies who are no longer org members
		foreach ($dbEntries as $name => $buddy) {
			if ($buddy->org_id === $org->guild_id && $buddy->mode !== 'add') {
				$this->db->table(static::DB_MEMBERS)
					->where("name", $name)
					->where("org_id", $org->guild_id)
					->delete();
				$this->buddylistManager->remove($name, 'alliance');
				unset($this->allianceMembers[$name]);
			}
		}

		$this->logger->notice("Finished Roster update for {$org->orgname}");
		if (isset($callback)) {
			$callback(...$args);
		}
	}

	/**
	 * Add an organization to your alliance
	 */
	#[NCA\HandlesCommand("alliance")]
	public function allianceAddCommand(
		CmdContext $context,
		#[NCA\Str("add")] string $action,
		PNonNumber $orgName
	): void {
		$hasOrglist = $this->eventManager->getKeyForCronEvent(86400, "findorgcontroller.parseAllOrgsEvent") !== null;
		if (!$hasOrglist) {
			$context->reply(
				$this->text->blobWrap(
					"In order to be able to search for orgs by name, you need to ",
					$this->text->makeBlob(
						"enable the ORGLIST_MODULE",
						"[" . $this->text->makeChatcmd(
							"enable it now",
							"/tell <myname> config mod ORGLIST_MODULE enable all"
						) . "] and wait a bit"
					),
					"."
				)
			);
			return;
		}
		if (!$this->findOrgController->isReady()) {
			$this->findOrgController->sendNotReadyError($context);
			return;
		}
		$orgs = $this->findOrgController->lookupOrg($orgName());
		$count = count($orgs);
		if ($count === 0) {
			$context->reply("No matches found.");
			return;
		}
		$blob = $this->formatResults($orgs);
		$msg = $this->text->makeBlob("Org Search Results for '{$orgName}' ($count)", $blob);
		$context->reply($msg);
	}

	public function getOrg(int $orgId): ?Organization {
		return $this->db->table("organizations")
			->where("id", $orgId)
			->asObj(Organization::class)
			->first();
	}

	/**
	 * Add an organization to your alliance
	 */
	#[NCA\HandlesCommand("alliance")]
	public function allianceAddIdCommand(
		CmdContext $context,
		#[NCA\Str("add")] string $action,
		int $orgId
	): void {
		$org = $this->getOrg($orgId);
		if (!isset($org)) {
			$context->reply("No organization with ID <highlight>{$orgId}<end> found.");
			return;
		}
		$alliance = new AllianceOrg();
		$alliance->org_id = $org->id;
		$alliance->added_by = $context->char->name;
		$alliance->added_dt = time();
		try {
			$this->db->insert(static::DB_ORGS, $alliance, null);
		} catch (SQLException $e) {
			$context->reply("The organization <highlight>{$org->name}<end> is already a member of this alliance.");
			return;
		}
		$context->reply("Added the organization <highlight>{$org->name}<end> to this alliance.");
		$this->guildManager->getByIdAsync(
			$org->id,
			null,
			true,
			[$this, "updateRosterForGuild"],
			[$context, "reply"],
			"Added all members of <highlight>{$org->name}<end> to the roster."
		);
	}

	/**
	 * Show a list of the orgs in your alliance
	 */
	#[NCA\HandlesCommand("alliance")]
	public function allianceListCommand(
		CmdContext $context,
		#[NCA\Str("list")] string $action
	): void {
		$query = $this->db->table(static::DB_ORGS, "a")
			->join("organizations as o", "a.org_id", "o.id")
			->join(static::DB_MEMBERS . " as m", "m.org_id", "a.org_id")
			->groupBy("a.org_id", "a.added_dt", "a.added_by", "o.name")
			->select("a.*", "o.name");
		$query->selectRaw($query->rawFunc("COUNT", "*", "members")->getValue());
		/** @var Collection<AllianceOrgStats> */
		$orgs = $query->asObj(AllianceOrgStats::class);
		$count = $orgs->count();
		if ($count === 0) {
			$context->reply("There are currently no orgs in your alliance.");
			return;
		}
		$blob = "";
		foreach ($orgs as $org) {
			$blob .= "<pagebreak><header2>{$org->name} ({$org->org_id})<end>\n".
				"<tab>Members: <highlight>{$org->members}<end>\n".
				"<tab>Joined: <highlight>" . $this->util->date($org->added_dt) . "<end>\n".
				"<tab>Added by: <highlight>{$org->added_by}<end>\n".
				"<tab>Action: [" . $this->text->makeChatcmd("remove", "/tell <myname> alliance rem {$org->org_id}") . "]\n\n";
		}
		$msg = $this->text->makeBlob("Orgs in your alliance ($count)", $blob);
		$context->reply($msg);
	}

	/**
	 * Remove an org from your alliance
	 */
	#[NCA\HandlesCommand("alliance")]
	public function allianceRemCommand(
		CmdContext $context,
		PRemove $action,
		int $orgId
	): void {
		$org = $this->getOrg($orgId);
		if (!isset($org)) {
			$context->reply("No organization with ID <highlight>{$orgId}<end> found.");
			return;
		}
		$deleted = $this->db->table(static::DB_ORGS)
			->where("org_id", $org->id)
			->delete();
		if ($deleted < 1) {
			$context->reply("The organization <highlight>{$org->name}<end> is not member of this alliance.");
			return;
		}
		$this->db->table(static::DB_MEMBERS)
			->where("org_id", $org->id)
			->asObj(AllianceMember::class)
			->each(function (AllianceMember $member) {
				$this->buddylistManager->remove($member->name, "alliance");
			});
		$deleted = $this->db->table(static::DB_MEMBERS)
			->where("org_id", $org->id)
			->delete();
		$context->reply(
			"Removed the organization <highlight>{$org->name}<end> ".
			"along with <highlight>{$deleted}<end> members from this alliance."
		);
	}

	/**
	 * @param Organization[] $orgs
	 */
	public function formatResults(array $orgs): string {
		$blob = '';
		foreach ($orgs as $org) {
			$addLink = $this->text->makeChatcmd('add', "/tell <myname> alliance add {$org->id}");
			$blob .= "<{$org->faction}>{$org->name}<end> ({$org->id}) - {$org->num_members} members [$addLink]\n\n";
		}
		return $blob;
	}
}
