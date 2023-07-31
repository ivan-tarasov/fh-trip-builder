DROP TABLE IF EXISTS `countries`;

CREATE TABLE `countries` (
    `code` char(2) NOT NULL DEFAULT '',
    `title` varchar(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--/*!9 LOCK TABLES countries WRITE */;

INSERT INTO `countries` (`code`, `title`)
VALUES
	('at','Austria'),
	('au','Australia'),
	('bd','Bangladesh'),
	('br','Brazil'),
	('ca','Canada'),
	('ch','Switzerland'),
	('cn','China'),
	('cy','Cyprus'),
	('de','Germany'),
	('es','Spain'),
	('fr','France'),
	('gb','United Kingdom'),
	('it','Italy'),
	('jp','Japan'),
	('kg','Kyrgyzstan'),
	('mx','Mexico'),
	('nz','New Zealand'),
	('ru','Russia'),
	('sd','Sudan'),
	('tr','Turkey'),
	('tz','Tanzania'),
	('ua','Ukraine'),
	('ug','Uganda'),
	('us','United States of America');

--UNLOCK TABLES;
