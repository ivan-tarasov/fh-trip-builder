<?php
if (!defined('__ROOT__')) {
    define('__ROOT__', dirname(__FILE__, 2));
}

require_once __ROOT__ . '/vendor/autoload.php';

require_once __ROOT__ . '/class/Timer.class.php';
require_once __ROOT__ . '/class/MysqliDb.class.php';

// Quick functions class
require_once __ROOT__ . '/class/Functions.class.php';

class API
{
    /**
     * Minimum security at this time...
     *
     * @var string $_token
     */
    private string $_token = 'SomeAPItoken_$ecretWORD---orHASH';

    /**
     * @var $db
     */
    public static $db;

    public function __construct()
    {
        ob_clean();
        header_remove();

        header("Content-type: application/json; charset=utf-8");
        header("Access-Control-Allow-Methods: OPTIONS,GET,POST,PUT,DELETE");
        header("Access-Control-Max-Age: 3600");
        header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

        try {
            try {
                $CURL_headers = getallheaders();

                // Simple X-AUTH
                if (empty($CURL_headers['X-Auth-Token']) || $this->_token !== $CURL_headers['X-Auth-Token']) {
                    // throw new APIstatusCode(401);
                    (new API)->errorCode(401);
                }

                Functions::DBCheck();

                self::$db = Functions::$db;
            } catch (Exception $e) {
                http_response_code($e->getMessage());

                throw new statusCode($e->getMessage());
            }
        } catch (statusCode $e) {
            echo $e->errorCode();
            die();
        }
    }

    /**
     * @param null $tablePrefix
     * @return void
     */
    public function SQLSearchWhere($tablePrefix = null): void
    {
        $directions = $this->checkQueryType();

        $tablePrefix = !empty($tablePrefix)
            ? $tablePrefix . '.'
            : null;

        self::$db->where($tablePrefix . 'departure_airport', $directions['from'], 'IN');
        self::$db->where($tablePrefix . 'arrival_airport', $directions['to'], 'IN');
        self::$db->where($tablePrefix . 'departure_time', [DEPARTURE_START, DEPARTURE_END], 'BETWEEN');

        if (!empty(SESSION['filterAirlines'])) {
            self::$db->where($tablePrefix . 'airline', SESSION['filterAirlines'], 'IN');
        }
    }

    /**
     * Check departure and arrive codes
     *
     * @return array
     */
    private function checkQueryType(): array
    {
        $return = [];

        // DEPARTURE
        self::$db->where('city_code', FROM);
        
        $count_from = self::$db->get('airports', null, 'code');

        if (0 !== self::$db->count) {
            foreach ($count_from as $airport) {
                $return['from'][] = $airport['code'];
            }
        } else {
            $return['from'][] = FROM;
        }

        // ARRIVAL
        self::$db->where('city_code', TO);

        $count_to = self::$db->get('airports', null, 'code');
        
        if (0 !== self::$db->count) {
            foreach ($count_to as $airport) {
                $return['to'][] = $airport['code'];
            }
        } else {
            $return['to'][] = TO;
        }

        return $return;
    }

    public function errorCode($code)
    {
        $statusError = [
            400 => 'Bad request. Check API documentation',
            401 => 'Access denied. Bad credentials, check API token',
            500 => 'Internal Server Error. Something wrong on our side'
        ];

        $errorMsg = [
            'code'    => $code,
            'message' => $statusError[$code]
        ];

        header('TRIPBUILDER-ERROR-MESSAGE: ' . $statusError[$code]);

        return json_encode($errorMsg, JSON_PRETTY_PRINT);
    }

}

//class statusCode extends Exception
//{
//    public function errorCode()
//    {
//        $statusError = [
//            400 => 'Bad request. Check API documentation',
//            401 => 'Access denied. Bad credentials, check API token',
//            500 => 'Internal Server Error. Something wrong on our side'
//        ];
//
//        $errorMsg = [
//            'code'    => $this->getMessage(),
//            'message' => $statusError[$this->getMessage()]
//        ];
//
//        header('TRIPBUILDER-ERROR-MESSAGE: ' . $statusError[$this->getMessage()]);
//
//        return json_encode($errorMsg, JSON_PRETTY_PRINT);
//    }
//}
