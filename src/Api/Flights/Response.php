<?php

declare(strict_types=1);

namespace TripBuilder\Api\Flights;

use Exception;
use TripBuilder\Api\AbstractApi;
use TripBuilder\Api\ApiResponder;
use TripBuilder\Http\HttpStatus;
use TripBuilder\Http\Input;
use TripBuilder\Party;
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
    private const string DATA_INFANT_COUNT = 'infant_count';
    private const string DATA_FLIGHT_ID = 'id';

    /**
     * @throws Exception
     */
    public function get(): void
    {
        // Typed the same way a query string is -- this body is as untrusted
        // as one, and Input already settles presence, type and default in
        // one place for exactly this reason.
        $data = new Input($this->data);

        // Throw Bad Request Exception if data or one of the necessary params is empty
        if ($this->data === []
            || $data->str(self::DATA_TRIPTYPE) === ''
            || $data->str(self::DATA_DEPART) === ''
            || $data->str(self::DATA_ARRIVE) === ''
            || $data->str(self::DATA_DEPART_DATE) === ''
            || $data->int(self::DATA_ADULT_COUNT) === 0
        ) {
            ApiResponder::badRequest();
        }

        $tripType = TripType::tryFrom($data->str(self::DATA_TRIPTYPE));

        if ($tripType === null) {
            ApiResponder::badRequest('Wrong trip type');
        }

        // The JSON API still pages. That is its published contract, and the
        // browser's growing list is a view concern — so the page number is
        // turned into the window the search now takes, and the paging fields
        // are put back on the response below.
        $page = max(1, $data->int(self::DATA_PAGE, 1));

        $query = new FlightSearchQuery(
            offset: ($page - 1) * self::PAGE_SIZE,
            limit: self::PAGE_SIZE,
            sort: $data->str(self::DATA_SORT, SortMethod::Price->value),
            from: $data->str(self::DATA_DEPART),
            to: $data->str(self::DATA_ARRIVE),
            departDate: $data->str(self::DATA_DEPART_DATE),
            returnDate: $data->str(self::DATA_RETURN_DATE),
            // Falls back to a lone adult when the payload asks for a party
            // that cannot fly -- no adult, or more laps than adults.
            party: Party::fromCounts(
                $data->int(self::DATA_ADULT_COUNT),
                $data->int(self::DATA_CHILD_COUNT),
                $data->int(self::DATA_INFANT_COUNT),
            ) ?? new Party(),
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
        $flightId = new Input($this->data)->int(self::DATA_FLIGHT_ID);

        // Throw Bad Request Exception if depart_id is empty
        if ($this->data === [] || $flightId === 0) {
            ApiResponder::badRequest();
        }

        $flight = new FlightFinder($this->connection())->findOne($flightId);

        if ($flight === null) {
            ApiResponder::notFound('Flight not found');
        }

        $this->sendResponse(HttpStatus::Ok, $flight);
    }
}
