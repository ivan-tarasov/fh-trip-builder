[![Trip Builder Demo][badge-demo-img]][badge-demo-url]
[![Last commit][badge-github-last-commit-img]][badge-github-last-commit-url]
[![Open Pull-Requests][badge-github-pr-open-img]][badge-github-pr-open-url][![Closed Pull-Requests][badge-github-pr-closed-img]][badge-github-pr-closed-url]
[![Open issues][badge-github-issues-open-img]][badge-github-issues-open-url][![Closed issues][badge-github-issues-closed-img]][badge-github-issues-closed-url]
[![LinkedIn][badge-linkedin-img]][badge-linkedin-url]

[![TripBulder form screenshot][project-screenshot]](https://trip-builder.tarasov.ca/)

# Trip Builder

The Air Trips Builder is an application designed to help users search for one-way and round-trip flights easily. The application comes with built-in databases for airports, airlines, and countries, providing a comprehensive flight booking experience. Users can also order flights and manage their bookings through a personal page.

## About The Project

PHP Coding Assignment for the Backend PHP Developer role at [FlightHub][flighthub-url].

## Features
- Search one-way and round-trip flights
- Access to a database of airports, airlines, and countries
- Flight ordering functionality
- Personalized user pages to manage ordered flights

## Built With

[![PHP version][php-logo]][php-url]
[![MySQL version][mysql-logo]][mysql-url]
[![Bootstrap][bootstrap-logo]][bootstrap-url]
[![JQuery][jquery-logo]][jquery-url]
[![FontAwesome][fontawesome-logo]][fontawesome-url]

## Installation

### 1. Clone the Repository
First, clone the repository using the following command:
```bash
git clone https://github.com/ivan-tarasov/fh-trip-builder.git
```

### 2. Install Dependencies
Navigate to the project directory and install the required dependencies using Composer:
```bash
cd fh-trip-builder
composer install
```

### 3. Configure Environment
Copy the sample environment file to create a new .env file:
```bash
cp .env.sample .env
```
Edit the .env file and provide your MySQL database credentials:
```bash
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=database_name
DB_USERNAME=database_user
DB_PASSWORD=database_password
```

### 4. Run Installation Command
Execute the following command to run the installation process:
```bash
php noah install
```

### 5. Generate Flights
To generate flight data, use the following command:
```bash
php noah flights:add
```

### 6. Access the Project
You're all set! Open your preferred web browser and navigate to the project URL to start using the application.

## Noah

Noah is the command line interface (CLI) tool included with the Trip Builder Project. It resides at the root of the application as the `noah` script and offers a variety of useful commands to assist you in building and managing application.

### Getting Started
To get started with Noah, you need to navigate to the root directory of your Trip Builder Project in your terminal.

### Viewing Available Commands
To see a comprehensive list of all available Noah commands, you can use the following command:
```bash
php noah list
```
This will display a list of commands that you can utilize for various tasks.

### Command Help Screens
For each command, there is a built-in "help" screen that provides information about the command's available arguments and options. To access this help screen, simply prepend the command with help. For example, if you want to learn more about the flights:add command, you can use:
```bash
php noah help flights:add
```
This will provide you with detailed information on how to use the flights:add command effectively.

### Available Commands
Here are some of the available commands in Noah:

#### Installing Database Tables and Seeding Data
To set up the necessary database tables and populate them with initial data, you can use the install command:
```bash
php noah install
```

#### Database Management
1. `db:clear`: Purge all data from database tables.
   ```bash
   php noah db:clear
   ```
   or
   ```bash
   php noah db:clear 10000
   ```
   Tris will generate and add 10,000 flights to database.

#### Flights Management
1. `flights:add`: Generate flights and add them to the database.
   ```bash
   php noah flights:add
   ```

2. `flights:cleaning`: cleaning flights
   ```bash
   php noah flights:cleaning
   ```
   This will delete flights older than today date from the database.

### Conclusion
Noah CLI simplifies various tasks related to the Trip Builder Project. By utilizing its commands and their respective options, you can efficiently build and manage application.
For more detailed information about each command and its usage, don't hesitate to consult the command's help screen using the help command as demonstrated above.

## Contributing

If you have a suggestion that would make TripBuilder better, please fork the repo and create a pull request. You can also simply open an issue with the tag "enhancement".

1. Fork the TripBuilder
2. Create your Feature Branch (`git checkout -b feature/SuggestionFeature`)
3. Commit your Changes (`git commit -m 'Add some SuggestionFeature'`)
4. Push to the Branch (`git push origin feature/SuggestionFeature`)
5. Open a Pull Request

## License

Distributed under the MIT License. See `LICENSE.txt` for more information.

[badge-demo-img]: https://img.shields.io/website?label=demo:%20trip-builder.tarasov.ca&style=for-the-badge&url=https%3A%2F%2Ftrip-builder.tarasov.ca%2F
[badge-demo-url]: https://trip-builder.tarasov.ca/
[badge-github-last-commit-img]: https://img.shields.io/github/last-commit/ivan-tarasov/fh-trip-builder?style=for-the-badge&logo=github
[badge-github-last-commit-url]: https://github.com/ivan-tarasov/fh-trip-builder/commits/master
[badge-github-repo-size-img]: https://img.shields.io/github/repo-size/ivan-tarasov/fh-trip-builder?style=for-the-badge&logo=github
[badge-github-repo-size-url]: https://github.com/ivan-tarasov/fh-trip-builder/archive/refs/heads/master.zip
[badge-github-pr-open-img]: https://img.shields.io/github/issues-pr/ivan-tarasov/fh-trip-builder?style=for-the-badge&logo=github
[badge-github-pr-open-url]: https://github.com/ivan-tarasov/fh-trip-builder/pulls
[badge-github-pr-closed-img]: https://img.shields.io/github/issues-pr-closed/ivan-tarasov/fh-trip-builder?style=for-the-badge&color=fca510&label=
[badge-github-pr-closed-url]: https://github.com/ivan-tarasov/fh-trip-builder/pulls?q=is%3Apr+is%3Aclosed
[badge-github-issues-open-img]: https://img.shields.io/github/issues/ivan-tarasov/fh-trip-builder?style=for-the-badge&logo=github
[badge-github-issues-open-url]: https://github.com/ivan-tarasov/fh-trip-builder/issues
[badge-github-issues-closed-img]: https://img.shields.io/github/issues-closed/ivan-tarasov/fh-trip-builder?style=for-the-badge&color=fca510&label=
[badge-github-issues-closed-url]: https://github.com/ivan-tarasov/fh-trip-builder/issues?q=is%3Aissue+is%3Aclosed

[badge-linkedin-img]: https://img.shields.io/badge/-LinkedIn-black.svg?style=for-the-badge&logo=linkedin&colorB=555
[badge-linkedin-url]: https://www.linkedin.com/in/ivan-tarasov-ca/

[readme-url]: https://github.com/ivan-tarasov/fh-trip-builder/blob/master/README.md
[flighthub-url]: https://flighthubgroup.com/
[php-logo]: https://img.shields.io/badge/php-%3E%208.0.3-blue?style=for-the-badge
[php-url]: https://www.php.net/ChangeLog-8.php#PHP_8_0
[mysql-logo]: https://img.shields.io/badge/mysql-%3E%205.7-blue?style=for-the-badge
[mysql-url]: https://www.mysql.com/
[bootstrap-logo]: https://img.shields.io/badge/Bootstrap%205.3.1-563D7C?style=for-the-badge&logo=bootstrap&logoColor=white
[bootstrap-url]: https://getbootstrap.com
[jquery-logo]: https://img.shields.io/badge/jQuery%203.3.1-0769AD?style=for-the-badge&logo=jquery&logoColor=white
[jquery-url]: https://jquery.com
[fontawesome-logo]: https://img.shields.io/badge/FontAwesome%206.1.1-228ae6?style=for-the-badge&logo=fontawesome&logoColor=white
[fontawesome-url]: https://fontawesome.com
[pulls-shield]: https://img.shields.io/bitbucket/pr-raw/karapuzoff/trip-builder?style=for-the-badge
[pulls-url]: https://github.com/ivan-tarasov/fh-trip-builder/pulls


[project-screenshot]: http://static-tripbuilder.tarasov.ca.s3-website.ca-central-1.amazonaws.com/images/git/form.png
