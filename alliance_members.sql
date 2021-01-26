CREATE TABLE IF NOT EXISTS `alliance_members_<myname>` (
	`name` VARCHAR(15) NOT NULL PRIMARY KEY,
	`org_id` INT NOT NULL,
	`mode` VARCHAR(7),
	`logged_off` INT DEFAULT 0
);