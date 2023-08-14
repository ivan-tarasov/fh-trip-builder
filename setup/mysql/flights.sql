DROP TABLE IF EXISTS flights;

CREATE TABLE flights (
    id int(6) NOT NULL AUTO_INCREMENT,
    airline char(2) NOT NULL DEFAULT '',
    number smallint(4) NOT NULL,
    departure_airport char(3) NOT NULL DEFAULT '',
    departure_time datetime NOT NULL,
    arrival_airport char(3) NOT NULL DEFAULT '',
    arrival_time datetime NOT NULL,
    distance int(5) NOT NULL,
    duration int(4) NOT NULL,
    price decimal(6,2) NOT NULL,
    rating decimal(3,2) DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
