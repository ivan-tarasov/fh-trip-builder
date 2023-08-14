DROP TABLE IF EXISTS bookings;

CREATE TABLE bookings (
    id int(6) unsigned NOT NULL AUTO_INCREMENT,
    created datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    session_id varchar(40) NOT NULL DEFAULT '',
    flight_a int(6) NOT NULL,
    flight_b int(6) DEFAULT NULL,
    departure_time datetime DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
