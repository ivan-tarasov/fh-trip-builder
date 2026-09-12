[![Trip Builder Demo][badge-demo-img]][badge-demo-url]
[![Last commit][badge-github-last-commit-img]][badge-github-last-commit-url]
[![Open Pull-Requests][badge-github-pr-open-img]][badge-github-pr-open-url][![Closed Pull-Requests][badge-github-pr-closed-img]][badge-github-pr-closed-url]
[![Open issues][badge-github-issues-open-img]][badge-github-issues-open-url][![Closed issues][badge-github-issues-closed-img]][badge-github-issues-closed-url]
[![LinkedIn][badge-linkedin-img]][badge-linkedin-url]

[![TripBulder form screenshot][project-screenshot]](https://trip-builder.tarasov.ca/)

<!-- Everything below the about:start marker is rendered on the site's /about
     page, straight from this file, so editing the README updates the page. The
     marker is an HTML comment, so it stays invisible on GitHub, and comments
     are stripped before rendering, so notes like this one never reach the page.

     Held back: the badges, the screenshot, and the title below them. The
     screenshot is served over http:// from an S3 website endpoint, which a
     browser blocks as mixed content on the https site -- that is a technical
     constraint, not a judgement about the content. Moving the asset somewhere
     with https would let the marker move up above it.

     The title is held back because the page supplies its own heading. Rendered,
     it put a second <h1> immediately under the page's, saying the project's
     name where the page had just said what the page is.

     An about:end marker is also honoured if one is added, to stop the page
     early. There isn't one: the whole README is the page. -->

# Trip Builder

<!-- about:start -->

The Air Trips Builder is an application designed to help users search for one-way and round-trip flights easily. The application comes with built-in databases for airports, airlines, and countries, providing a comprehensive flight booking experience. Users can also order flights and manage their bookings through a personal page.

## About The Project

PHP Coding Assessment for the Backend PHP Developer role at [FlightHub][flighthub-url].

## Built With

[![PHP version][php-logo]][php-url]
[![MySQL version][mysql-logo]][mysql-url]
[![Bootstrap][bootstrap-logo]][bootstrap-url]
[![JQuery][jquery-logo]][jquery-url]
[![FontAwesome][fontawesome-logo]][fontawesome-url]

## Features

### Flight Search
Effortlessly search for both one-way and round-trip flights with ease.

### Robust REST API
Leverage the capabilities of built-in REST API, offered flexibility and convenience in accessing data.

### Autofill Search Form
Simplify search experience with autofill functionality, which populates search forms with relevant airport data.

### Flexible Flight Sorting
Sorting search results according to preferences, ensuring to find the ideal flights for any journey.

### Tailored Departure Time
Customized flights search by specifying preferred departure time, ensuring a travel schedule that suits.

### Airline Filtering
Efficiently narrowed down search results by filtering airlines, allowing to focus on preferred carriers.

### Paginated Search Results
Navigate search results effortlessly with paginated display, enhancing readability and user experience.

### Seamless Flight Ordering
Streamline flight booking process with intuitive flight ordering functionality.

### Personalized User Pages
Personalized user pages that shows all information about bookings and empower to efficiently manage ordered flights.

### Comprehensive Database
A comprehensive database containing information about airports, airlines, and countries.

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

Copy the web server config into place as well:
```bash
cp .htaccess.example .htaccess
```
The live `.htaccess` is not tracked by git, because hosting panels such as cPanel
own that file and rewrite it — notably the generated block that pins the PHP
version. Keeping it untracked stops a deploy from overwriting those changes. If
your host has already created an `.htaccess`, leave it alone and just make sure
it contains **both** rewrite blocks from `.htaccess.example` -- the refusal
first, then the front controller.

The refusal is the one that matters. The document root is this repository, so
without it every file here is a URL: `.env`, `composer.lock`, and any `.php`
under `src/` or `tests/` executed on request. After copying, check from
outside:

```bash
for p in .env noah composer.lock src/Cdn.php config/; do
  printf '%-16s %s\n' "/$p" "$(curl -sS -o /dev/null -w '%{http_code}' "https://YOUR-HOST/$p")"
done
```

Every line should read `403`. `/`, `/airside` and anything under `/frontend/`
should still answer `200`.

### 4. Run Installation Command
Execute the following command to run the installation process:
```bash
php noah install
```

### 5. Import Help Articles
The help articles and the categories grouping them live in
`config/content/help` as markdown, and are loaded into the database by their
own command:
```bash
php noah articles:import
```
This is a separate step on purpose. `php noah install` seeds every table from a
CSV and refreshes every column as it goes, so an article that had been edited
would be reverted on the next install. Keeping articles out of the seeders means
the install cannot overwrite them, and this command is the only thing that
writes them.

### 6. Generate Flights
To generate flight data, use the following command:
```bash
php noah flights:add
```

### 7. Access the Project
You're all set! Open your preferred web browser and navigate to the project URL to start using the application.

## Tests

```bash
composer test:unit
```

Needs nothing but PHP — no database, no `.env`, nothing installed beyond
`composer install`. On a fresh clone it either passes or has found a real
defect; there is no third answer, and a test is kept out of this suite if it
needs rows. `UnitSuiteNeedsNoDatabaseTest` is what holds that line.

```bash
composer test
```

Both suites, which means it needs a database. Without one it fails on a single
test that says so and points here, rather than skipping a fifth of the suite
and reporting success. `composer test:integration` runs that half on its own.

### Pointing the integration tests at a database

They take their settings from `.env`, the same ones `php noah install` uses, so
if the install worked the tests will too. Where your MySQL is somewhere else —
MAMP's port, a container, a socket you would rather not use — export the
difference and it wins over `.env`:

```bash
DB_HOST=127.0.0.1 DB_PORT=8889 DB_SOCKET= composer test:integration
```

An empty value counts as an answer: `DB_SOCKET=` above is how you say "connect
over TCP", rather than through the socket `.env` names.

A dozen of these tests skip themselves when the generated flight network
happens to have nothing on the route they picked — no flights, or no
connections to fold. Those skips are honest and depend on `php noah
flights:add` having been run, so a run that reports a few of them has not gone
wrong.

### The rest of the gates

```bash
composer lint
composer stan
composer cscheck
```

A syntax check across every file, static analysis, and the coding standard.
`composer csfix` writes the standard's fixes rather than reporting them.

## Releases

Merging a pull request into `develop` tags a release automatically
(`.github/workflows/release.yml`). Versions are the calendar —
`vYEAR.MONTH.COUNT`, where the count says which release of that month it is:

| Tag           | Meaning                                |
|---------------|----------------------------------------|
| `v2026.9.1`   | first release in September 2026        |
| `v2026.9.2`   | second, later the same month           |
| `v2026.10.1`  | first release in October               |
| `v2027.3.1`   | first release of 2027, cut in March    |

The year and month are read from the merge commit's own date in UTC, and the
count from the tags already in that month. Nothing carries and nothing resets,
which has two consequences worth knowing: no tag ever ends in `.0`, and the
middle number is the calendar month rather than a sequence — so the first
release of a year is `v2027.3.1` if that is when it happens, not `v2027.1.1`.

Why not semver: nothing installs this app as a dependency, so "will this
upgrade break me" has no asker. A date says the more useful thing, which is how
fresh a build is. Tags up to `v2.24.1` are the old scheme and are left as they
were; `v2026.9.1` is the first calendar one.

A pull request still needs exactly one label, enforced by `pr-labels.yml`,
which fails a pull request carrying none or several. (Add it as a required
status check on `develop` for that to block merging.) The labels no longer
decide the version — the calendar does — so they now say what kind of change
it was, and `no-release` is the one that matters to the workflow:

- `release:major` / `release:minor` / `release:patch` — classify the change,
  and make pull requests findable later.
- `no-release` — merge without cutting a release at all.

Release notes are written by the workflow, not by GitHub: one line per commit
since the previous tag, with UTC timestamps. The application footer shows the
current tag, so a deployed server needs `git fetch --tags` for it to appear.

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

#### Flights Management
1. `flights:add`: Generate flights and add them to the database.
   ```bash
   php noah flights:add
   ```
   or
   ```bash
   php noah flights:add 10000
   ```
   Tris will generate and add 10,000 flights to database.


2. `flights:cleaning`: cleaning flights
   ```bash
   php noah flights:cleaning
   ```
   This will delete flights older than today date from the database.

#### Articles Management
1. `articles:import`: Make the help categories and articles in the database
   match the files in `config/content/help`.
   ```bash
   php noah articles:import
   ```
   One file per article, and one per category in the `categories` subdirectory.
   Both are a `key: value` header between `---` fences followed by markdown,
   and both are refused by name — naming the file and the key — if a key is
   missing, repeated or misspelled, rather than imported with a gap.

   An **article** header carries `title`, `category`, `icon`, `position` and
   `summary`, and optionally a `short` label for the footer, where some titles
   are wider than the column. Its markdown is the article.

   A **category** header carries `title`, `icon` and `position`, and optionally
   an `accent`, which names a palette colour rather than being one: `blue`,
   `green`, `orange`, `violet` or `pink`. Anything else is not an error — it
   draws the default blue, because a colour is not worth failing an import
   over. It has no `summary` key: its markdown is the one sentence shown under
   the heading on the hub, so a category with nothing to say is refused the way
   an article with no prose is.

   `position` orders articles within their category, and categories against
   each other. An article naming a category that does not exist stops the
   import rather than being filed somewhere plausible.

   Re-running it is safe and quiet. Every article is written on every run, but
   `updated_at` only moves when the title, short label, summary or prose
   actually differs from the stored copy — so the date on an article is the date
   its content last changed, not the date somebody last ran the import.

   **It also removes.** A row whose file has gone is deleted, along with its
   translations and, for an article, the votes cast on it — those are keyed on
   the slug, so leaving them would hand a future article a tally about a page
   nobody can read. Without this, deleting a file left the article on every
   database that had already imported it while a fresh install never had it, so
   the two quietly stopped agreeing.

   Two things make that safe to have on by default. The command refuses the
   whole run when it finds no files at all, so a mistyped path or an unmounted
   volume cannot empty the tables. And it refuses when an article names a
   category no file describes, so deleting a category still in use fails before
   anything is written rather than orphaning its articles.

   To see what would be written **and what would be removed**, without doing
   either:
   ```bash
   php noah articles:import --dry-run
   ```

2. `airside:import`: The same, for the Airside posts in `config/content/airside`.
   ```bash
   php noah airside:import
   ```
   Airside is the travel section, as against help, which is what a reader needs
   in order to finish a booking here. Same terms as the articles above: no
   seeder CSV, so `app:install` cannot revert a post, and the files are the
   whole truth — a post whose file is deleted is removed from the table on the
   next run.

   It refuses the whole run on a bad file rather than importing the rest: a
   missing `hero_alt`, an image no file backs, a date that does not exist, or a
   file name a URL could not hold. `--dry-run` reports without writing.

#### Currency Management
1. `currency:rates`: Refresh the conversion rates every price is converted with.
   ```bash
   php noah currency:rates
   ```
   Fetches the European Central Bank's reference rates and stores them against
   the day the ECB published them, so running it twice in an afternoon corrects
   one row rather than writing two. Safe to put on a daily cron; the ECB
   publishes on working days, so a weekend run simply records Friday's figures
   again.

   Nothing is written unless the whole response is usable — a truncated body
   would otherwise leave some currencies on an older rate while claiming all of
   them had just been confirmed. To see what would be stored without storing it:
   ```bash
   php noah currency:rates --dry-run
   ```
   `app:install` seeds a starting set of rates, so a fresh clone converts before
   this has ever run. It is not part of `app:install` on purpose: a deploy should
   not be able to fail because a third party is down.

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


[project-screenshot]: https://d1kf00o84yn9o.cloudfront.net/images/git/form_v2.png
