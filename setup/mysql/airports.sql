DROP TABLE IF EXISTS `airports`;

CREATE TABLE `airports` (
  `code` char(3) NOT NULL DEFAULT '',
  `enabled` int(1) DEFAULT NULL,
  `country_code` char(2) NOT NULL,
  `region_code` char(6) NOT NULL DEFAULT '',
  `city_code` char(3) DEFAULT '',
  `city` varchar(255) NOT NULL DEFAULT '',
  `timezone` varchar(255) NOT NULL DEFAULT '',
  `title` varchar(255) NOT NULL DEFAULT '',
  `latitude` decimal(9,6) NOT NULL,
  `longitude` decimal(9,6) NOT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--/*!17 LOCK TABLES airports WRITE */;

INSERT INTO `airports` (`code`, `enabled`, `country_code`, `region_code`, `city_code`, `city`, `timezone`, `title`, `latitude`, `longitude`)
VALUES
	('AOE',1,'tr','TR-26','AOE','Anadolu','Europe/Istanbul','Eskisehir Anadolu Airport',39.809898,30.519400),
	('AYT',1,'tr','TR-07','AYT','Antalya','Europe/Istanbul','Antalya International Airport',36.898701,30.800501),
	('CMG',1,'br','BR-MS','CMG','Corumba','America/Campo_Grande','Corumba International Airport',-19.011944,-57.671391),
	('DME',1,'ru','RU-MOS','MOW','Moscow','Europe/Moscow','Domodedovo International Airport',55.408798,37.906300),
	('DUS',1,'de','DE-NW','DUS','Duesseldorf','Europe/Berlin','Duesseldorf International Airport',51.289501,6.766780),
	('FCO',1,'it','IT-62','ROM','Rome','Europe/Rome','Leonardo da Vinci-Fiumicino Airport',41.800278,12.238889),
	('FRA',1,'de','DE-HE','FRA','Frankfurt','Europe/Berlin','Frankfurt Airport',50.033333,8.570556),
	('HND',1,'jp','JP-13','TYO','Tokyo','Asia/Tokyo','Tokyo International Airport',35.552299,139.779999),
	('IST',1,'tr','TR-34','IST','Istanbul','Europe/Istanbul','Istanbul Atatuerk Airport',40.976898,28.814600),
	('JFK',1,'us','US-NY','NYC','New York','America/New_York','John F. Kennedy International Airport',40.639722,-73.778889),
	('KBP',1,'ua','UA-32','KBP','Kiev','Europe/Kiev','Kyiv Boryspil International Airport',50.345001,30.894699),
	('KMG',1,'cn','CN-53','KMG','Kunming','Asia/Shanghai','Kunming Changshui International Airport',25.101944,102.929167),
	('LAX',1,'us','US-CA','LAX','Los Angeles','America/Los_Angeles','Los Angeles International Airport',33.942501,-118.407997),
	('LCY',1,'gb','GB-ENG','LON','London','Europe/London','London City Airport',51.505299,0.055278),
	('LED',1,'ru','RU-SPE','LED','Saint Petersburg','Europe/Moscow','Pulkovo Airport',59.800301,30.262501),
	('LGA',1,'us','US-NY','NYC','New York','America/New_York','La Guardia Airport',40.777199,-73.872597),
	('LHR',1,'gb','GB-ENG','LON','London','Europe/London','London Heathrow Airport',51.470600,-0.461941),
	('LWO',1,'ua','UA-46','LWO','Lviv','Europe/Uzhgorod','Lviv International Airport',49.812500,23.956100),
	('MCO',1,'us','US-FL','MCO','Orlando','America/New_York','Orlando International Airport',28.429399,-81.308998),
	('MEX',1,'mx','MX-DIF','MEX','Mexico City','America/Mexico_City','Benito Juarez International Airport',19.436300,-99.072098),
	('MIA',1,'us','US-FL','MIA','Miami','America/New_York','Miami International Airport',25.793200,-80.290604),
	('MUC',1,'de','DE-BY','MUC','Munich','Europe/Berlin','Munich International Airport',48.353802,11.786100),
	('MXP',1,'it','IT-25','MXP','Milano','Europe/Rome','Milano Malpensa Airport',45.630600,8.728110),
	('NRT',1,'jp','JP-12','NRT','Narita','Asia/Tokyo','Narita International Airport',35.764702,140.386002),
	('OGN',1,'jp','JP-47','OGN','Yonaguni','Asia/Tokyo','Yonaguni Airport',24.466900,122.977997),
	('PEK',1,'cn','CN-11','PEK','Beijing','Asia/Shanghai','Beijing Capital International Airport',40.080101,116.584999),
	('PVG',1,'cn','CN-31','PVG','Shanghai','Asia/Shanghai','Shanghai Pudong International Airport',31.143400,121.805000),
	('SVO',1,'ru','RU-MOS','MOW','Moscow','Europe/Moscow','Sheremetyevo International Airport',55.972599,37.414600),
	('SYD',1,'au','AU-NSW','SYD','Sydney','Australia/Sydney','Sydney International Airport',-33.946098,151.177002),
	('UKS',1,'ua','UA-43','UKS','Sevastopol','Europe/Simferopol','Sevastopol International Airport',44.688999,33.570999),
	('VCE',1,'it','IT-34','VCE','Venice','Europe/Rome','Venice Marco Polo Airport',45.505299,12.351900),
	('VIA',1,'br','BR-SC','VIA','Videira','America/Sao_Paulo','Videira Airport',-26.999701,-51.141899),
	('YHU',1,'ca','CA-QC','YMQ','Montreal','America/Toronto','Saint-Hubert Airport',45.517502,-73.416901),
	('YKQ',1,'ca','CA-QC','YKQ','Waskaganish','America/Toronto','Waskaganish Airport',51.473301,-78.758301),
	('YKZ',1,'ca','CA-ON','YKZ','Buttonville','America/Toronto','Buttonville Municipal Airport',43.862202,-79.370003),
	('YOP',1,'ca','CA-AB','YOP','Rainbow Lake','America/Edmonton','Rainbow Lake Airport',58.491402,-119.407997),
	('YUL',1,'ca','CA-QC','YMQ','Montreal','America/Toronto','Elliott Trudeau International Airport',45.457714,-73.749908),
	('YYZ',1,'ca','CA-ON','YYZ','Toronto','America/Toronto','Toronto Pearson International Airport',43.677200,-79.630600);

--UNLOCK TABLES;
