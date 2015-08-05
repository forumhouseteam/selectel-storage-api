<?php namespace FHTeam\SelectelStorageApi\Utility\Http;

use Exception;
use FHTeam\SelectelStorageApi\Exception\UnexpectedHttpStatusException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Pool;

/**
 * Class HttpWrapper
 *
 * @package ForumHouse\SelectelStorageApi\Utility\Http
 */
class HttpClient
{
    /**
     * @var int[]
     */
    protected $goodHttpStatusCodes = [];

    /**
     * @var string[]
     */
    protected $customErrors;

    /**
     * @var Client
     */
    protected $guzzleClient;

    /**
     * HttpClient constructor.
     */
    public function __construct()
    {
        $this->guzzleClient = new Client();
    }

    public function createGuzzleRequest($method, $url)
    {
        return $this->guzzleClient->createRequest($method, $url, ['exceptions' => false]);
    }

    /**
     * @param int[] $codes
     *
     * @return HttpClient
     */
    public function setGoodHttpStatusCodes(array $codes)
    {
        $this->goodHttpStatusCodes = $codes;

        return $this;
    }

    /**
     * @param string[] $customErrors
     */
    public function setCustomErrors(array $customErrors)
    {
        $this->customErrors = $customErrors;
    }

    /**
     * Send body-less http request
     *
     * @param HttpRequest $request
     *
     * @return bool
     * @throws Exception
     * @throws UnexpectedHttpStatusException
     */
    public function send(HttpRequest $request)
    {
        $guzzleRequest = $request->getGuzzleRequest();
        $guzzleResponse = $this->guzzleClient->send($guzzleRequest);
        $request->setGuzzleResponse($guzzleResponse);

        $statusCode = $guzzleResponse->getStatusCode();

        if (in_array($statusCode, $this->goodHttpStatusCodes)) {
            return true;
        }

        throw $this->getException(
            $statusCode,
            $guzzleRequest->getMethod(),
            $guzzleRequest->getUrl(),
            $guzzleResponse->getHeaders()
        );
    }

    /**
     * @param HttpRequest[] $requests
     * @param object[]      $objects
     *
     * @return array
     */
    public function sendMany(array $requests, array $objects)
    {
        $requestArray = array_map(
            function (HttpRequest $item) {
                return $item->getGuzzleRequest();
            },
            $requests
        );

        $responses = Pool::batch($this->guzzleClient, $requestArray);

        $result = [
            'ok' => [],
            'failed' => [],
        ];

        /** @var ResponseInterface $response */
        foreach ($responses as $key => $response) {
            if ($response instanceof RequestException) {
                /** @var RequestException $response */
                $result['failed'][] = [
                    'exception' => $response->getMessage(),
                    'request_url' => $response->getRequest()->getUrl(),
                    'effective_url' => $response->hasResponse() ? $response->getResponse()->getEffectiveUrl() :
                        $response->getRequest()->getUrl(),
                    'object' => $objects[$key],
                    'status_code' => $response->hasResponse() ? $response->getResponse()->getStatusCode() : '0',
                    'reason' => $response->hasResponse() ? $response->getResponse()->getReasonPhrase() : '',
                ];
                continue;
            }

            if (in_array($response->getStatusCode(), $this->goodHttpStatusCodes)) {
                $result['ok'][] = $objects[$key];
                continue;
            }

            /** @var RequestInterface $request */
            $request = $requestArray[$key];
            $result['failed'][] = [
                'request_url' => $request->getUrl(),
                'effective_url' => $response->getEffectiveUrl(),
                'object' => $objects[$key],
                'status_code' => $response->getStatusCode(),
                'reason' => $response->getReasonPhrase(),
            ];
        }

        return $result;
    }

    /**
     * @param int      $statusCode
     * @param string   $method
     * @param string   $url
     * @param string[] $headers
     *
     * @return Exception|UnexpectedHttpStatusException
     */
    protected function getException($statusCode, $method, $url, array $headers)
    {
        if (isset($this->customErrors[$statusCode])) {
            $className = $this->customErrors[$statusCode];

            return new $className;
        }

        return new UnexpectedHttpStatusException(
            $statusCode,
            "Unexpected http status '$statusCode' from [$method] '$url'. Headers are: ".json_encode($headers)
        );
    }
}