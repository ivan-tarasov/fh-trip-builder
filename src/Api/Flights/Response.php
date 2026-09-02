<?php

declare(strict_types=1);

namespace TripBuilder\Api\Flights;

use Exception;
use TripBuilder\Api\AbstractApi;
use TripBuilder\Api\ApiResponder;
use TripBuilder\Api\HttpStatus;
use TripBuilder\Service\FlightFinder;
use TripBuilder\TripType;

class Response extends AbstractApi
{
    // The API's page size. It was the view's too until the search page started
    // growing its list instead; the API keeps paging, so the number lives here.
    private const int PAGE_SIZE = 10;

    private const string DATA_PAGE = 'page';
    private const string DATA_SORT = 'sort';
    private const string DATA_TRIPTYPE = 'trip_type';
    private const string DATA_DEPART = 'from';
    private const string DATA_ARRIVE = 'to';
    private const string DATA_DEPART_DATE = 'depart_date';
    private const string DATA_RETURN_DATE = 'return_date';
    private const string DATA_ADULT_COUNT = 'adult_count';
    private const string DATA_CHILD_COUNT = 'child_count';
    private const string DATA_FLIGHT_ID = 'id';

    /**
     * @throws Exception
     */
    public function get(): void
    {
        // Throw Bad Request Exception if data or one of the necessary params is empty
        if (empty($this->data)
            || empty($this->data[self::DATA_TRIPTYPE])
            || empty($this->data[self::DATA_DEPART])
            || empty($this->data[self::DATA_ARRIVE])
            || empty($this->data[self::DATA_DEPART_DATE])
            || empty($this->data[self::DATA_ADULT_COUNT])
        ) {
            ApiResponder::badRequest();
        }

        $tripType = TripType::tryFrom($this->data[self::DATA_TRIPTYPE]);

        if ($tripType === null) {
            ApiResponder::badRequest('Wrong trip type');
        }

        // The JSON API still pages. That is its published contract, and the
        // browser's growing list is a view concern — so the page number is
        // turned into the window the search now takes, and the paging fields
        // are put back on the response below.
        $page = max(1, (int) ($this->data[self::DATA_PAGE] ?? 1));

        $query = new FlightSearchQuery(
            offset: ($page - 1) * self::PAGE_SIZE,
            limit: self::PAGE_SIZE,
            sort: $this->data[self::DATA_SORT] ?? SortMethod::Price->value,
            from: $this->data[self::DATA_DEPART],
            to: $this->data[self::DATA_ARRIVE],
            departDate: $this->data[self::DATA_DEPART_DATE],
            returnDate: $this->data[self::DATA_RETURN_DATE] ?? '',
            adultNum: (int) $this->data[self::DATA_ADULT_COUNT],
            childNum: (int) ($this->data[self::DATA_CHILD_COUNT] ?? 0),
        );

        $result = new FlightFinder($this->connection())->search($query, $tripType);

        $this->sendResponse(HttpStatus::Ok, [
            'current_page' => $page,
            'total_pages' => (int) ceil($result['total_flights'] / self::PAGE_SIZE),
            'per_page' => self::PAGE_SIZE,
            ...$result,
        ]);
    }

    /**
     * @throws Exception
     */
    public function getOne(): void
    {
        // Throw Bad Request Exception if depart_id is empty
        if (empty($this->data) || empty($this->data[self::DATA_FLIGHT_ID])) {
            ApiResponder::badRequest();
        }

        $flight = new FlightFinder($this->connection())->findOne((int) $this->data[self::DATA_FLIGHT_ID]);

        if ($flight === null) {
            ApiResponder::notFound('Flight not found');
        }

        $this->sendResponse(HttpStatus::Ok, $flight);
    }
}
