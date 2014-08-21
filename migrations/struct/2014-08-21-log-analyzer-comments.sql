
ALTER TABLE `system_errors`
ADD `comments` text COLLATE 'utf8_general_ci' NULL AFTER `message`,
COMMENT='';
