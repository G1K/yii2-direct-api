<?php
namespace g1k\direct;

use yii\base\Component;
use Yii;

/**
 * Компонент для работы с API Yandex.Direct
 * @author Alexey Salnikov <me@iamsalnikov.ru>
 * @author Alexandr Sidorov <mr@g1k.ru>
 *
 * @method archiveCampaign($param = array())
 * @method createOrUpdateCampaign($param = array())
 * @method deleteCampaign($param = array())
 * @method getCampaignParams($param = array())
 * @method getCampaignsList($param = array())
 * @method getCampaignsListFilter($param = array())
 * @method getCampaignsParams($param = array())
 * @method resumeCampaign($param = array())
 * @method stopCampaign($param = array())
 * @method unArchiveCampaign($param = array())
 *
 * @method archiveBanners($param = array())
 * @method createOrUpdateBanners($param = array())
 * @method deleteBanners($param = array())
 * @method getBanners($param = array())
 * @method getBannerPhrases($param = array())
 * @method getBannerPhrasesFilter($param = array())
 * @method moderateBanners($param = array())
 * @method resumeBanners($param = array())
 * @method stopBanners($param = array())
 * @method unArchiveBanners($param = array())
 *
 * @method setAutoPrice($param = array())
 * @method updatePrices($param = array())
 *
 * @method getBalance($param = array())
 * @method getSummaryStat($param = array())
 * @method createNewReport($param = array())
 * @method deleteReport($param = array())
 * @method getReportList()
 * @method createNewWordstatReport($param = array())
 * @method deleteWordstatReport($param = array())
 * @method getWordstatReport($param = array())
 * @method getWordstatReportList()
 * @method createNewForecast($param = array())
 * @method deleteForecastReport($param = array())
 * @method getForecast($param = array())
 * @method getForecastList()
 *
 * @method createNewSubclient($params = array())
 * @method getClientInfo($param = array())
 * @method getClientsList($param = array())
 * @method getClientsUnits($param = array())
 * @method getSubClients($param = array())
 * @method updateClientInfo($param = array())
 *
 * @method getAvailableVersions()
 * @method getChanges($param = array())
 * @method getRegions()
 * @method getRubrics()
 * @method getStatGoals($param = array())
 * @method getTimeZones()
 * @method getVersion()
 * @method pingAPI()
 */
class DirectApi extends Component
{

    /**
     * Id приложения
     * @var string $clientId
     */
    public $clientId;

    /**
     * Пароль приложения
     * @var string
     */
    public $clientSecret;

    /**
     * Тип ответа от сервера Яндекса
     * @var string
     */
    public $responseType = 'code';

    /**
     * На каком языке получать ответы из яндекса
     * @var string
     */
    public $locale = 'ru';

    /**
     * Песочница или боевое подключение
     * @author Alexey Makhov <makhov.alex@gmail.com>
     * @var boolean
     */
    public $useSandbox = false;

    /**
     * Ссылка для авторизации на директе
     * @var string
     */
    private $_authorizeLink;

    /**
     * Код при авторизации
     * @var string
     */
    private $_code;

    /**
     * Токен от директа
     * @var string
     */
    private $_token;

    /**
     * Здесь хранится данные если они получены
     * @var array
     */
    private $_data;

    /**
     * Здесь хранится код ошибки, если она произошла
     * @var string
     */
    private $_error;

    /**
     * Здесь строка ошибки при вызове методов API
     * @var string
     */
    private $_errorStr;

    /**
     * Здесь описание ошибки при вызове методов API
     * @var string
     */
    private $_errorDetail;

    /**
     * Логин пользователя, с данными которого мы работаем
     * @var string
     */
    private $_login;

    /**
     * Включить отладку
     * @var bool
     */
    private $debug = 0;

    private $time;

    /**
     * Curl
     * @var Curl
     */
    private $_ch;
    private $_curlOptions = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_AUTOREFERER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:5.0) Gecko/20110619 Firefox/5.0',
        CURLOPT_TIMEOUT => 0,
        CURLOPT_POST => true,
    );

    /**
     * URL подключение к API. Либо боевой, либо песочница
     * @var string
     */
    private $_apiUrl;

    const AUTHORIZE_URL = 'https://oauth.yandex.ru/authorize';
    const TOKEN_URL = 'https://oauth.yandex.ru/token';
    const JSON_API_URL = 'https://api.direct.yandex.ru/live/v4/json/';
    const SANDBOX_JSON_API_URL = 'https://api-sandbox.direct.yandex.ru/live/v4/json/';

    public function init()
    {
        $this->_apiUrl = ($this->useSandbox) ? self::SANDBOX_JSON_API_URL : self::JSON_API_URL;

        # Инициализируем CURL
        $this->_ch = curl_init();
        curl_setopt_array($this->_ch, $this->_curlOptions);
        curl_setopt($this->_ch, CURLOPT_URL, $this->_apiUrl);

        # Установим строку для авторизации
        $this->_authorizeLink = self::AUTHORIZE_URL . '?' . http_build_query(array(
                'response_type' => $this->responseType,
                'client_id' => $this->clientId,
            ));

        # Если язык не установлен, тогда возьмем его из установок приложения
        if (!$this->locale) {
            $this->locale = Yii::$app->language;
        }
    }


    /**
     * Получение ссылки для авторизации
     * @param string $state - произвольный параметр состояния
     * @return string
     */
    public function getAuthorizeUrl($state = '')
    {
        return $state ? $this->_authorizeLink . '&state=' . $state : $this->_authorizeLink;
    }

    /**
     * Получаем токен из директа
     * @param $code - код для авторизации
     * @return string|null В случае успешного получения токена возвращается токен, иначе null.
     * Значение ошибки можно получить из функции getError()
     */
    public function getDirectToken($code)
    {
        $this->clearErrors();
        $this->_code = $code;

        $result = Yii::$app->curl->post(self::TOKEN_URL, array(
            'grant_type' => 'authorization_code',
            'code' => $this->_code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret
        ));

        $result = json_decode($result);

        # Если все прошло без ошибки
        if (empty($result->error)) {
            $this->_token = $result->access_token;
        } else {
            $this->_error = $result->error;
        }

        return $this->_token;
    }

    /**
     * Установка токена
     * @param string $token
     * @return YiiDirectApi
     */
    public function setToken($token)
    {
        $this->_token = $token;
        return $this;
    }

    /**
     * Получение url api
     * return null|string
     */
    public function getApiUrl()
    {
        return $this->_apiUrl;
    }

    /**
     * Получение данных
     * return null|array
     */
    public function getData()
    {
        return $this->_data;
    }

    /**
     * Установка данных
     * @param $data
     * @return $this
     */
    public function setData($data)
    {
        $this->_data = $data;
        return $this;
    }

    /**
     * Получение ошибки
     * return null|string
     */
    public function getError()
    {
        return $this->_error;
    }

    /**
     * Установка ошибки
     * @param $error
     * @return $this
     */
    public function setError($error)
    {
        $this->_error = $error;
        return $this;
    }

    /**
     * Получаем логин пользователя, с которым мы работаем
     * @return string
     */
    public function getLogin()
    {
        return $this->_login;
    }

    /**
     * Установка логина пользователя, с которым будем работать
     * @param $login
     * @return YiiDirectApi
     */
    public function setLogin($login)
    {
        $this->_login = $login;
        return $this;
    }

    /**
     * Установка заголовка ошибки
     * @param string $errorStr
     * @return $this
     */
    public function setErrorStr($errorStr)
    {
        $this->_errorStr = $errorStr;
        return $this;
    }

    /**
     * Получение заголовка ошибки
     * @return string
     */
    public function getErrorStr()
    {
        return $this->_errorStr;
    }

    /**
     * Получение всю информацию о ошибке
     * @return string
     */
    public function getErrorFull()
    {
        return $this->_error . ' ' . $this->_errorStr . ' ' . $this->_errorDetail;
    }

    /**
     * Очистка информации об ошибках
     * @return $this
     */
    public function clearErrors()
    {
        $this->_error = null;
        $this->_errorStr = null;
        $this->_errorDetail = null;
        return $this;
    }

    /**
     * Выполняем запрос
     * @return array
     */
    private function _execCurl($data)
    {
        curl_setopt($this->_ch, CURLOPT_POSTFIELDS, $data);
        $c = curl_exec($this->_ch);
        if (curl_errno($this->_ch)) {
            throw new CException(curl_error($this->_ch));
            $c = false;
        }
        return $c;
    }

    /**
     * Запрос к API
     * @param string $method
     * @param array $params
     * @return bool|array
     */
    public function apiQuery($method, $params = array())
    {
        $this->clearErrors();
        $params = array(
            'method' => $method,
            'param' => $params,
            'locale' => $this->locale,
            'application_id' => $this->clientId,
            'token' => $this->_token
        );
        $params = $this->utf8($params);
        $params = json_encode($params);

        if ((int)$this->debug == 1)
            $this->time = microtime(true);

        $result = $this->_execCurl($params);
        $result = json_decode($result);

        if (!$result) {
            $error = 'Не удается открыть адрес: ' . $this->getApiUrl() . ". (" . $params . ')' . ' Логин: ' . $this->getLogin();
            throw new \Exception($error);
        } else if (!empty($result)) {
            if (isset($result->error_code) && isset($result->error_str)) {
                $this->setError($result->error_code)->setErrorStr($result->error_str);
                if ($result->error_str != 'Нет статистики для данной кампании' && in_array($result->error_code, [53, 54, 58, 510, 251, 513])) {
                    $error = "Запрос {$method}: " . $this->ErrorFull . (!empty($this->login) ? ' ' . $this->login . '.' : '');
                    throw new \Exception($error);
                }
                $result = false;
            }
        }

        if ((int)$this->debug == 1) {
            $error = 'Запрос Яндекс.Директ API.' . $method . ': ' . round(microtime(true) - $this->time, 4) . ' сек.';
            throw new \Exception($error);
        }

        $this->setData($result->data);

        return $result;
    }

    /**
     * Перекодировка
     * @param $struct
     * @return mixed
     */
    public function utf8($struct)
    {
        foreach ($struct as $key => $value) {
            if (is_array($value)) {
                $struct[$key] = $this->utf8($value);
            } elseif (is_string($value)) {
                $struct[$key] = utf8_encode($value);
            }
        }
        return $struct;
    }

    /**
     * Вызов методов
     * @param string $method
     * @param array $args
     * @return mixed|void
     */
    public function __call($method, $args)
    {
        $params = empty($args) ? array() : $args[0];
        return $this->apiQuery(ucfirst($method), $params);
    }
}
