ALTER TABLE `glpi_plugin_ocsinventoryng_ocsservers` ADD `import_customapp` TINYINT(1) NOT NULL DEFAULT '0';

CREATE TABLE `glpi_plugin_ocsinventoryng_customapps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `computers_id` int(11) NOT NULL DEFAULT '0',
  `path` varchar(255) DEFAULT NULL,
  `text` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `computers_id` (`computers_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;