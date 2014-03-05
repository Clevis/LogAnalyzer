
CREATE TABLE `system_errors` (
	`error_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	`hash` char(32) NOT NULL COMMENT 'MD5 from file, line, level and message',
	`status` enum('active','resolved') NOT NULL DEFAULT 'active',
	`file` varchar(200) NOT NULL,
	`line` int(10) unsigned NOT NULL,
	`message` varchar(500) NOT NULL,
	`level` enum('Fatal error','Warning','Deprecated','Notice') NOT NULL,
	`last_time` datetime NOT NULL,
	`count` int(10) unsigned NOT NULL,
	`issue_id` int(10) unsigned DEFAULT NULL,
	PRIMARY KEY (`error_id`),
	UNIQUE KEY `hash` (`hash`),
	KEY `status` (`status`,`last_time`),
	KEY `last_time` (`last_time`),
	KEY `issue_id` (`issue_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `system_errors_redscreens` (
	`error_id` int(10) unsigned NOT NULL,
	`hash` varchar(70) NOT NULL,
	`time` datetime NOT NULL,
	UNIQUE KEY `error_id` (`error_id`,`hash`),
	CONSTRAINT `system_errors_redscreens_ibfk_2` FOREIGN KEY (`error_id`) REFERENCES `system_errors` (`error_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `system_errors_resolutions` (
	`error_id` int(10) unsigned NOT NULL,
	`person_id` int(10) unsigned NOT NULL,
	`time` datetime NOT NULL,
	`comment` varchar(1000) DEFAULT NULL,
	KEY `error_id` (`error_id`),
	KEY `person_id` (`person_id`),
	CONSTRAINT `system_errors_resolutions_ibfk_1` FOREIGN KEY (`error_id`) REFERENCES `system_errors` (`error_id`) ON DELETE CASCADE,
	CONSTRAINT `system_errors_resolutions_ibfk_2` FOREIGN KEY (`person_id`) REFERENCES `persons` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


CREATE TABLE `system_errors_urls` (
	`error_id` int(10) unsigned NOT NULL,
	`hash` char(32) NOT NULL,
	`url` varchar(1000) NOT NULL,
	`count` int(10) unsigned NOT NULL,
	`last_time` datetime NOT NULL,
	PRIMARY KEY (`error_id`,`hash`),
	CONSTRAINT `system_errors_urls_ibfk_1` FOREIGN KEY (`error_id`) REFERENCES `system_errors` (`error_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
