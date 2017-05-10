<?php
namespace Elastica\Transport;

use Elastica\Exception\Connection\HttpException;
use Elastica\Exception\PartialShardFailureException;
use Elastica\Exception\ResponseException;
use Elastica\JSON;
use Elastica\Request;
use Elastica\Response;
use Elastica\Util;

/**
 * Elastica Http Transport object.
 *
 * @author Nicolas Ruflin <spam@ruflin.com>
 */
class Http extends AbstractTransport
{
    /**
     * Http scheme.
     *
     * @var string Http scheme
     */
    protected $_scheme = 'http';

    /**
     * Curl resource to reuse.
     *
     * @var resource Curl resource to reuse
     */
    protected static $_curlConnection;

    /**
     * Makes calls to the elasticsearch server.
     *
     * All calls that are made to the server are done through this function
     *
     * @param \Elastica\Request $request
     * @param array             $params  Host, Port, ...
     *
     * @throws \Elastica\Exception\ConnectionException
     * @throws \Elastica\Exception\ResponseException
     * @throws \Elastica\Exception\Connection\HttpException
     *
     * @return \Elastica\Response Response object
     */
    public function exec(Request $request, array $params)
    {
        $connection = $this->getConnection();

        $conn = $this->_getConnection($connection->isPersistent());

        // If url is set, url is taken. Otherwise port, host and path
        $url = $connection->hasConfig('url') ? $connection->getConfig('url') : '';

        if (!empty($url)) {
            $baseUri = $url;
        } else {
            $baseUri = $this->_scheme.'://'.$connection->getHost().':'.$connection->getPort().'/'.$connection->getPath();
        }

        $requestPath = $request->getPath();
        if (!Util::isDateMathEscaped($requestPath)) {
            $requestPath = Util::escapeDateMath($requestPath);
        }

        $baseUri .= $requestPath;

        $query = $request->getQuery();

        //Auto add fielddata to all text fields
        foreach ($request as &$item) {
            if (isset($item['method']) && $item['method'] == 'PUT') {
                foreach ($item['data']['mappings'] as $index => $fields) {
                    if (isset ($fields['properties'])) {
                        foreach ($fields['properties'] as $fieldName => $fieldValue) {
                            if ($fieldValue['type'] == 'text') {
                                if ($fieldValue['type'] == 'nested' || $fieldValue['type'] == 'object'){
                                    $item['data']['mappings'][$index]['properties'][$fieldName] = $this-> formatElasticaData($item['data']['mappings'][$index]['properties'][$fieldName]);
                                }else {
                                    $item['data']['mappings'][$index]['properties'][$fieldName]['fielddata'] = true;
                                }
                            }
                        }
                    }
                }
            }
        }

        if (!empty($query)) {
            $baseUri .= '?'.http_build_query($query);
        }

        curl_setopt($conn, CURLOPT_URL, $baseUri);
        curl_setopt($conn, CURLOPT_TIMEOUT, $connection->getTimeout());
        curl_setopt($conn, CURLOPT_FORBID_REUSE, 0);

        // Tell ES that we support the compressed responses
        // An "Accept-Encoding" header containing all supported encoding types is sent
        // curl will decode the response automatically if the response is encoded
        curl_setopt($conn, CURLOPT_ENCODING, '');

        /* @see Connection::setConnectTimeout() */
        $connectTimeout = $connection->getConnectTimeout();
        if ($connectTimeout > 0) {
            curl_setopt($conn, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        }

        $proxy = $connection->getProxy();

        // See: https://github.com/facebook/hhvm/issues/4875
        if (is_null($proxy) && defined('HHVM_VERSION')) {
            $proxy = getenv('http_proxy') ?: null;
        }

        if (!is_null($proxy)) {
            curl_setopt($conn, CURLOPT_PROXY, $proxy);
        }

        $username = $connection->getUsername();
        $password = $connection->getPassword();
        if (!is_null($username) && !is_null($password)) {
            curl_setopt($conn, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($conn, CURLOPT_USERPWD, "$username:$password");
        }

        $this->_setupCurl($conn);

        $headersConfig = $connection->hasConfig('headers') ? $connection->getConfig('headers') : [];

        $headers = [];

        if (!empty($headersConfig)) {
            $headers = [];
            while (list($header, $headerValue) = each($headersConfig)) {
                array_push($headers, $header.': '.$headerValue);
            }
        }

        // TODO: REFACTOR
        $data = $request->getData();
        $httpMethod = $request->getMethod();

        if (!empty($data) || '0' === $data) {
            if ($this->hasParam('postWithRequestBody') && $this->getParam('postWithRequestBody') == true) {
                $httpMethod = Request::POST;
            }

            if (is_array($data)) {
                $content = JSON::stringify($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $content = $data;

                // Escaping of / not necessary. Causes problems in base64 encoding of files
                $content = str_replace('\/', '/', $content);
            }

            if ($connection->hasCompression()) {
                // Compress the body of the request ...
                curl_setopt($conn, CURLOPT_POSTFIELDS, gzencode($content));

                // ... and tell ES that it is compressed
                array_push($headers, 'Content-Encoding: gzip');
            } else {
                curl_setopt($conn, CURLOPT_POSTFIELDS, $content);
            }
        } else {
            curl_setopt($conn, CURLOPT_POSTFIELDS, '');
        }

        curl_setopt($conn, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($conn, CURLOPT_NOBODY, $httpMethod == 'HEAD');

        curl_setopt($conn, CURLOPT_CUSTOMREQUEST, $httpMethod);

        $start = microtime(true);

        // cURL opt returntransfer leaks memory, therefore OB instead.
        ob_start();
        curl_exec($conn);
        $responseString = ob_get_clean();

        $end = microtime(true);

        // Checks if error exists
        $errorNumber = curl_errno($conn);

        $response = new Response($responseString, curl_getinfo($conn, CURLINFO_HTTP_CODE));
        $response->setQueryTime($end - $start);
        $response->setTransferInfo(curl_getinfo($conn));
        if ($connection->hasConfig('bigintConversion')) {
            $response->setJsonBigintConversion($connection->getConfig('bigintConversion'));
        }

        if ($response->hasError()) {
            throw new ResponseException($request, $response);
        }

        if ($response->hasFailedShards()) {
            throw new PartialShardFailureException($request, $response);
        }

        if ($errorNumber > 0) {
            throw new HttpException($errorNumber, $request, $response);
        }

        return $response;
    }

    /**
     * Recursive Traitement of ES data to set fielddata to true
     * @param $datas
     */
    protected function formatElasticaData($datas) {
        if (isset($datas['properties'])){
            foreach ($datas['properties'] as $dataName => $dataValue) {
                if ($dataValue['type'] == 'string'){
                    $datas['properties'][$dataName]['type'] = 'text';

                }
                if ($dataValue['type'] != 'nested' && $dataValue['type'] != 'object' && $dataValue['type'] != 'geo_point') {
                    $datas['properties'][$dataName]['fielddata'] = true;
                }
                else {
                    $datas = $this->formatElasticaData($dataValue);
                }
            }
        }
        return $datas;
    }

    /**
     * Called to add additional curl params.
     *
     * @param resource $curlConnection Curl connection
     */
    protected function _setupCurl($curlConnection)
    {
        if ($this->getConnection()->hasConfig('curl')) {
            foreach ($this->getConnection()->getConfig('curl') as $key => $param) {
                curl_setopt($curlConnection, $key, $param);
            }
        }
    }

    /**
     * Return Curl resource.
     *
     * @param bool $persistent False if not persistent connection
     *
     * @return resource Connection resource
     */
    protected function _getConnection($persistent = true)
    {
        if (!$persistent || !self::$_curlConnection) {
            self::$_curlConnection = curl_init();
        }

        return self::$_curlConnection;
    }
}
