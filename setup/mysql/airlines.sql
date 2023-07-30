DROP TABLE IF EXISTS airlines;

CREATE TABLE airlines (
  code char(2) NOT NULL DEFAULT '',
  title varchar(255) NOT NULL DEFAULT '',
  country_code char(2) DEFAULT '',
  traffic int(5) NOT NULL,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--/*!11 LOCK TABLES airlines WRITE */;

INSERT INTO airlines (code, title, country_code, traffic)
VALUES
	('3X','Japan Air Commuter','jp',123),
	('5Y','Atlas Air','us',87),
	('6U','Air Ukraine','ua',34),
	('8P','Pacific Coastal Airlines','ca',284),
	('A7','Air Plus Comet','es',12),
	('AA','American Airlines','us',4865),
	('AC','Air Canada','ca',884),
	('AF','Air France','fr',671),
	('AK','AirAsia','',408),
	('AS','Alaska Airlines, Inc.','us',855),
	('CA','Air China','cn',1492),
	('CX','Cathay Pacific Airways','',36),
	('CZ','China Southern Airlines','cn',2393),
	('DL','Delta Air Lines','us',3803),
	('EK','Emirates','ae',422),
	('EV','Atlantic Southeast Airlines','us',233),
	('FR','Ryanair','fr',2144),
	('G4','Allegiant Air','us',56),
	('GJ','Loong Air','cn',76),
	('GQ','Big Sky Airlines','us',32),
	('HQ','Harmony Airways','ca',125),
	('HU','Hainan Airlines','',90),
	('JH','Fuji Dream Airlines','jp',73),
	('JL','Japan Airlines','',239),
	('KJ','Asian Air','kg',211),
	('KW','Kelowna Flightcraft','ca',10),
	('LF','Contour Airlines','us',21),
	('LX','SWISS','',229),
	('MU','China Eastern Airlines','cn',2161),
	('NK','Spirit Airlines','us',809),
	('NZ','Eagle Airways','nz',544),
	('O9','Nova Airline','sd',31),
	('OB','Austrian Airtransport','at',11),
	('PW','Precision Air','tz',9),
	('QE','Crossair Europe','ch',15),
	('QH','Air Florida','us',290),
	('QR','Qatar Airways','qa',3412),
	('RV','Air Canada Rouge','ca',167),
	('S7','S7 Airlines','',286),
	('SK','SAS','',377),
	('SQ','Singapore Airlines','sg',250),
	('SU','Aeroflot','ru',557),
	('TK','Turkish Airlines','tr',1084),
	('U7','Air Uganda','ug',10),
	('UA','United Airlines','us',3608),
	('VC','Voyageur Airways','ca',42),
	('VS','Virgin Atlantic','gb',890),
	('WN','Southwest Airlines','us',3674),
	('Z5','GMG Airlines','bd',23),
	('ZU','Helios Airways','cy',56),
	('ZY','Sky Airlines','tr',5);

--UNLOCK TABLES;
