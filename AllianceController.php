<?php declare(strict_types=1);

namespace Nadybot\User\Modules\ALLIANCE_MODULE;

use Nadybot\Core\{
	BuddylistManager,
	CommandReply,
	DB,
	Event,
	EventManager,
	LoggerWrapper,
	Modules\PLAYER_LOOKUP\Guild,
	Modules\PLAYER_LOOKUP\GuildManager,
	Nadybot,
	SQLException,
	Text,
	Util,
};
use Nadybot\Modules\ORGLIST_MODULE\{
	FindOrgController,
	Organization,
};
use Nadybot\Modules\GUILD_MODULE\GuildController;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = "alliance",
 *		accessLevel = "mod",
 *		description = "Manage orgs of the alliance",
 *		help        = "alliance.txt"
 *	)
 */
class AllianceController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public FindOrgController $findOrgController;

	/** @Inject */
	public GuildController $guildController;

	/** @Inject */
	public GuildManager $guildManager;

	/** @Inject */
	public BuddylistManager $buddylistManager;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Logger */
	public LoggerWrapper $logger;

	/**
	 * The rank for each member of this bot's alliance
	 * [(string)name => (int)rank]
	 * @var array<string,int>
	 */
	public array $allianceMembers = [];

	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, "alliance_orgs");
		$this->db->loadSQLFile($this->moduleName, "alliance_members");

		$this->allianceMembers = [];
		$sql = "SELECT a.name, IFNULL(p.guild_rank_id, 6) AS guild_rank_id ".
			"FROM `alliance_members_<myname>` a ".
			"LEFT JOIN players p ON (".
				"a.name = p.name ".
				"AND p.dimension = '<dim>' ".
				"AND p.guild_id = a.org_id".
			") ".
			"WHERE a.mode != 'del'";
		$data = $this->db->query($sql);
		foreach ($data as $row) {
			$this->allianceMembers[$row->name] = (int)$row->guild_rank_id;
		}
	}

	/**
	 * @Event("timer(24hrs)")
	 * @Description("Download all alliance org information")
	 */
	public function downloadOrgRostersEvent(Event $eventObj): void {
		$this->updateOrgRosters();
	}

	/**
	 * @HandlesCommand("alliance")
	 * @Matches("/^alliance\s+update$/i")
	 */
	public function allianceUpdateCommand($message, $channel, $sender, $sendto, $args) {
		$sendto->reply("Starting Alliance Roster update");
		$this->updateOrgRosters([$sendto, "reply"], "Finished Alliance Roster update");
	}

	public function updateOrgRosters(?callable $callback=null, ...$args) {
		if ($this->guildController->isGuildBot()) {
			// return;
		}
		$this->logger->log('INFO', "Starting Alliance Roster update");

		/** @var AllianceOrg[] */
		$orgs = $this->db->fetchAll(AllianceOrg::class, "SELECT * FROM `alliance_orgs_<myname>`");

		$i = 0;
		foreach ($orgs as $org) {
			$i++;
			// Get the org info
			$this->guildManager->getByIdAsync(
				$org->org_id,
				$this->chatBot->vars["dimension"],
				true,
				[$this, "updateRosterForGuild"],
				function() use (&$i, $callback, $args) {
					if (--$i === 0) {
						if (isset($callback)) {
							$callback(...$args);
						}
						$this->logger->log('INFO', "Finished Alliance Roster update");
					}
				}
			);
		}
	}

	public function updateRosterForGuild(?Guild $org, ?callable $callback, ...$args): void {
		// Check if JSON file was downloaded properly
		if ($org === null) {
			$this->logger->log('ERROR', "Error downloading the guild roster JSON file");
			return;
		}

		if (count($org->members) === 0) {
			$this->logger->log('ERROR', "The organisation {$org->orgname} has no members. Not changing its roster");
			return;
		}

		// Save the current org_members table in a var
		/** @var AllianceMember[] */
		$data = $this->db->fetchAll(
			AllianceMember::class,
			"SELECT * FROM `alliance_members_<myname>`"
		);
		/** @var array<string,AllianceMember> */
		$dbEntries = [];
		foreach ($data as $row) {
			$dbEntries[$row->name] = $row;
		}

		$this->db->beginTransaction();

		// Going through each member of the org and add or update his/her status
		foreach ($org->members as $member) {
			// don't do anything if $member is the bot itself
			if (strtolower($member->name) === strtolower($this->chatBot->vars["name"])) {
				continue;
			}

			//If there's already data about the character just update them
			if (isset($dbEntries[$member->name])) {
				if ($dbEntries[$member->name]->mode === "del") {
					// members who are not on notify should not be on the buddy list but should remain in the database
					$this->buddylistManager->remove($member->name, 'alliance');
					unset($this->allianceMembers[$member->name]);
				} else {
					// add org members who are on notify to buddy list
					$this->buddylistManager->add($member->name, 'alliance');
					$this->allianceMembers[$member->name] = $member->guild_rank_id;

					// if member was added to notify list manually, switch mode to org and let guild roster update from now on
					if ($dbEntries[$member->name]->mode === "add") {
						$this->db->exec("UPDATE `alliance_members_<myname>` SET `mode` = 'org' WHERE `name` = ?", $member->name);
					}
				}
			//Else insert their data
			} else {
				// add new org members to buddy list
				$this->buddylistManager->add($member->name, 'alliance');
				$this->allianceMembers[$member->name] = $member->guild_rank_id;

				$this->db->exec(
					"INSERT INTO `alliance_members_<myname>` (`org_id`, `name`, `mode`) VALUES (?, ?, 'org')",
					$org->guild_id,
					$member->name
				);
			}
			unset($dbEntries[$member->name]);
		}

		$this->db->commit();

		// remove buddies who are no longer org members
		foreach ($dbEntries as $name => $buddy) {
			if ($buddy->org_id === $org->guild_id && $buddy->mode !== 'add') {
				$this->db->exec(
					"DELETE FROM `alliance_members_<myname>` WHERE `name` = ? AND `org_id` = ?",
					$name,
					$org->guild_id
				);
				$this->buddylistManager->remove($name, 'alliance');
				unset($this->allianceMembers[$name]);
			}
		}

		$this->logger->log('INFO', "Finished Roster update for {$org->orgname}");
		if (isset($callback)) {
			$callback(...$args);
		}
	}

	/**
	 * @HandlesCommand("alliance")
	 * @Matches("/^alliance add (.*[^\d].*)$/i")
	 */
	public function allianceAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args) {
		$hasOrglist = $this->eventManager->getKeyForCronEvent(86400, "findorgcontroller.parseAllOrgsEvent") !== null;
		if (!$hasOrglist) {
			$sendto->reply(
				"In order to be able to search for orgs by name, you need to ".
				$this->text->makeBlob(
					"enable the ORGLIST_MODULE",
					"[" . $this->text->makeChatcmd(
						"enable it now",
						"/tell <myname> config mod ORGLIST_MODULE enable all"
					) . "] and wait a bit"
				) . "."
			);
			return;
		}
		if (!$this->findOrgController->isReady()) {
			$this->findOrgController->sendNotReadyError($sendto);
			return;
		}
		$orgs = $this->findOrgController->lookupOrg($args[1]);
		$count = count($orgs);
		if ($count === 0) {
			$sendto->reply("No matches found.");
			return;
		}
		$blob = $this->formatResults($orgs);
		$msg = $this->text->makeBlob("Org Search Results for '{$args[1]}' ($count)", $blob);
		$sendto->reply($msg);
	}

	public function getOrg(int $orgId): ?Organization {
		$sql = "SELECT * FROM `organizations` WHERE `id`=?";

		/** @var ?Organization */
		return $this->db->fetch(Organization::class, $sql, $orgId);
	}

	/**
	 * @HandlesCommand("alliance")
	 * @Matches("/^alliance add (\d+)$/i")
	 */
	public function allianceAddIdCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args) {
		$org = $this->getOrg((int)$args[1]);
		if (!isset($org)) {
			$sendto->reply("No organization with ID <highlight>{$args[1]}<end> found.");
			return;
		}
		$alliance = new AllianceOrg();
		$alliance->org_id = $org->id;
		$alliance->added_by = $sender;
		$alliance->added_dt = time();
		try {
			$this->db->insert("alliance_orgs_<myname>", $alliance);
		} catch (SQLException $e) {
			$sendto->reply("The organization <highlight>{$org->name}<end> is already a member of this alliance.");
			return;
		}
		$sendto->reply("Added the organization <highlight>{$org->name}<end> to this alliance.");
		$this->guildManager->getByIdAsync(
			$org->id,
			$this->chatBot->vars["dimension"],
			true,
			[$this, "updateRosterForGuild"],
			[$sendto, "reply"],
			"Added all members of <highlight>{$org->name}<end> to the roster."
		);
	}

	/**
	 * @HandlesCommand("alliance")
	 * @Matches("/^alliance list$/i")
	 */
	public function allianceListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args) {
		/** @var AllianceOrg[] */
		$orgs = $this->db->fetchAll(
			AllianceOrg::class,
			"SELECT a.*, o.name, COUNT(*) AS `members` ".
			"FROM `alliance_orgs_<myname>` a ".
			"JOIN `organizations` o ON (a.`org_id`=o.`id`) ".
			"JOIN `alliance_members_<myname>` m ON (m.`org_id`=a.`org_id`) ".
			"GROUP BY a.`org_id` ".
			"ORDER BY o.name ASC"
		);
		$count = count($orgs);
		if ($count === 0) {
			$sendto->reply("There are currently no orgs in your alliance.");
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
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("alliance")
	 * @Matches("/^alliance (?:rem|del|rm|kick) (\d+)$/i")
	 */
	public function allianceRemCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args) {
		$org = $this->getOrg((int)$args[1]);
		if (!isset($org)) {
			$sendto->reply("No organization with ID <highlight>{$args[1]}<end> found.");
			return;
		}
		$deleted = $this->db->exec("DELETE FROM `alliance_orgs_<myname>` WHERE `org_id`=?", $org->id);
		if ($deleted < 1) {
			$sendto->reply("The organization <highlight>{$org->name}<end> is not member of this alliance.");
			return;
		}
		/** @var AllianceMember[] */
		$members = $this->db->fetchAll(
			AllianceMember::class,
			"SELECT * FROM `alliance_members_<myname>` WHERE `org_id`=?",
			$org->id
		);
		foreach ($members as $member) {
			$this->buddylistManager->remove($member->name, "alliance");
		}
		$deleted = $this->db->exec("DELETE FROM `alliance_members_<myname>` WHERE `org_id`=?", $org->id);
		$sendto->reply(
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
