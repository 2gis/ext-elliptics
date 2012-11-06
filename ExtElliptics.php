<?php
/*!
 * ExtElliptics is an extension for Yii Framework developed by Flamp Tech Team.
 * Provides methods to work with the Elliptics Network that is a fault tolerant key/value storage,
 * See http://www.ioremap.net/projects/elliptics for more info.
 *
 * Configuration properties:
 * -------------------------
 *      privateServerAddress    Private Elliptics Network Proxy address that used only by application itself (default: 127.0.0.1)
 *      publicServerAddress     Public Elliptics Network Proxy address that may be shown for application users (e.g. in public file URLs) (default: localhost)
 *      writePort               Elliptics Network Proxy write port (default: 8080)
 *      readPort                Elliptics Network Proxy read port (default: 80)
 *      monitoringPort          Elliptics Network Proxy monitoring port (default: 81)
 *      connectionTimeout       Represents the waiting time (in ms) while establishing connection with Elliptics Network Proxy
 *                              before terminating the attempt and throwing an error (default: 1000)
 *
 * Public methods (see detailed description near each method):
 * -----------------------------------------------------------
 *      upload($file, $storageFileId = null)
 *      multiUpload(array $files)
 *      get($storageFileId)
 *      getDownloadInfo($storageFileId)
 *      delete($storageFileId)
 *      exists($storageFileId)
 *      ping()
 *
 * Requirements:
 * -------------
 *  - Yii 1.1.x or above
 */

/**
 * @class ExtElliptics
 * @author Alexander Mironov <a.s.mironov@2gis.ru>
 * @author Alexey Ashurok <a.ashurok@2gis.ru>
 * @link http://flamp.ru
 * @copyright 2GIS
 * @version 1.1
 */
class ExtElliptics extends CApplicationComponent
{

    /**
     * Global settings
     * @var mixed
     */
    public $privateServerAddress = '127.0.0.1';
    public $publicServerAddress = 'localhost';
    public $writePort = '8080';
    public $readPort = '80';
    public $monitoringPort = '81';
    public $connectionTimeout = 1000;
    /**
     * @var resource common cURL handler
     */
    protected $curlHandler = null;

    public function init()
    {
        parent::init();
        Yii::import('ext.Elliptics.components.*');
        $this->curlHandler = $this->createCurlHandler();
    }

    public function __destruct()
    {
        if ($this->curlHandler !== null) {
            curl_close($this->curlHandler);
        }
    }

    /**
     * Ping the Elliptics Network Proxy. To conduct the operation the monitoring port is used.
     * @return bool is true in case of successful ping, otherwise false
     */
    public function ping()
    {
        $this->setRequestAddress("ping", $this->monitoringPort);
        try {
            return $this->executeRequest();
        } catch (EllipticsException $e) {
            Yii::log($e->getMessage(), CLogger::LEVEL_WARNING, __METHOD__);
            return false;
        }
    }

    /**
     * Upload a single file to the Elliptics Network. This operation uses extended mode of uploading method with custom params
     * to save current timestamp in a file metadata (it can be used to return correct "Last-Modified" header).
     * @param string $file path to file
     * @param null|string $storageFileId identifier with which file will be stored. In case of null
     *        basename (filename + extension) of file will be used.
     * @return bool is true if upload was successful, otherwise false
     * @throws EllipticsException
     */
    public function upload($file, $storageFileId = null)
    {
        $this->checkServerConnection();
        $storageFileId = $storageFileId ? $storageFileId : $this->getStorageFileId($file);
        $currentTimestamp = time();
        $requestAddress = "?name={$storageFileId}&timestamp={$currentTimestamp}&embed_timestamp=1";
        $this->setRequestAddress($requestAddress, $this->writePort);
        $this->setRequestParams(array(
            'post' => 1,
            'postfields' => $this->getFileContents($file),
        ));
        $requestResult = $this->executeRequest();
        return $this->extractUploadRequestResult($requestResult);
    }

    /**
     * Upload multiple files to the Elliptics Network using multithreaded cURL
     * @param array $files can take a couple of input variants:
     * 1) Array with specified storageFileId for each file:
     * array(
     *      'fileKey' => array(
     *          'storageFileId' => 'storageFileId',
     *          'path' => '/full/path/to/file.jpg'
     *      )
     * );
     * 2) Array without specifying new storageFileId, using basenames of files instead:
     * array(
     *     'fileKey' => '/full/path/to/file.jpg',
     * );
     * @return array containing bool results for each uploaded file with 'fileKey' preserving
     * @throws EllipticsException
     */
    public function multiUpload(array $files)
    {
        $this->checkServerConnection();
        $multiCurlHandler = curl_multi_init();
        $curlHandlers = array();
        $currentTimestamp = time();
        foreach($files as $fileId => $file) {
            $curlHandlers[$fileId] = $this->createCurlHandler();
            $storageFileId = $this->getStorageFileId($file);
            $requestAddress = "?name={$storageFileId}&timestamp={$currentTimestamp}&embed_timestamp=1";
            $this->setRequestAddress($requestAddress, $this->writePort, $curlHandlers[$fileId]);
            $this->setRequestParams(array(
                'post' => true,
                'postfields' => $this->getFileContents($file),
            ), $curlHandlers[$fileId]);
            curl_multi_add_handle($multiCurlHandler, $curlHandlers[$fileId]);
        }
        $requestsResults = $this->executeMultipleRequests($multiCurlHandler, $curlHandlers);
        foreach($requestsResults as $fileId => $requestResult) {
            $requestsResults[$fileId] = $this->extractUploadRequestResult($requestResult);
            curl_multi_remove_handle($multiCurlHandler, $curlHandlers[$fileId]);
        }
        curl_multi_close($multiCurlHandler);
        return $requestsResults;
    }

    /**
     * Delete file from the Elliptics Network by it's storage identifier
     * @param string $storageFileId
     * @return bool is true in case of successful deletion, otherwise false
     * @throws EllipticsException
     */
    public function delete($storageFileId)
    {
        $this->checkServerConnection();
        $this->setRequestAddress("delete/{$storageFileId}", $this->writePort);
        return $this->executeRequest();
    }

    /**
     * Get download info for a specified file, stored in the Elliptics Network
     * @param string $storageFileId
     * @return mixed is false in case of failure or an array with full download info otherwise
     * @throws EllipticsException
     */
    public function getDownloadInfo($storageFileId)
    {
        $this->checkServerConnection();
        $this->setRequestAddress("download-info/{$storageFileId}", $this->writePort);
        $xmlDownloadInfo = $this->executeRequest();
        if($xmlDownloadInfo) {
            $arrayDownloadInfo = array();
            $xmlIterator = new SimpleXMLIterator($xmlDownloadInfo);
            foreach($xmlIterator as $key => $value) {
                $arrayDownloadInfo[$key] = strval($value);
            }
            return $arrayDownloadInfo;
        }
        return false;
    }

    /**
     * Determines that a file with a specified identifier exists in the Elliptics Network
     * @param string $storageFileId
     * @return bool is true if file exists, otherwise false
     * @throws EllipticsException
     */
    public function exists($storageFileId)
    {
        $this->checkServerConnection();
        return $this->getDownloadInfo($storageFileId) ? true : false;
    }

    /**
     * Gets file content by specified identifier
     * @param string $storageFileId
     * @return mixed is false in case of failure or a string with file content otherwise
     * @throws EllipticsException
     */
    public function get($storageFileId)
    {
        $this->checkServerConnection();
        $this->setRequestAddress($storageFileId, $this->readPort);
        $fileContent = $this->executeRequest();
        return $fileContent ? $fileContent : false;
    }

    /**
     * Check connection with the Elliptics Network proxy and throw an EllipticsException in case of failure
     * @throws EllipticsException
     */
    protected function checkServerConnection()
    {
        if(!$this->ping()) {
            throw new EllipticsException("Cannot connect to Elliptics server.");
        }
    }

    /**
     * Initialize new curl handler resource instance and return it
     * @return resource
     */
    protected function createCurlHandler()
    {
        $curlHandler = curl_init();
        curl_setopt($curlHandler, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandler, CURLOPT_HEADER, 0);
        curl_setopt($curlHandler, CURLOPT_CONNECTTIMEOUT_MS, $this->connectionTimeout);
        return $curlHandler;
    }

    /**
     * Setup request address and port for common or specified cURL handler
     * @param string $address
     * @param int|null $port
     * @param resource $curlHandler
     */
    protected function setRequestAddress($address, $port = null, $curlHandler = null)
    {
        $curlHandler = $curlHandler ? $curlHandler : $this->curlHandler;
        curl_setopt($curlHandler, CURLOPT_URL,  "http://{$this->privateServerAddress}/{$address}");
        if ($port) {
            curl_setopt($curlHandler, CURLOPT_PORT, $port);
        }
    }

    /**
     * Setup cURL params for common or specified cURL handler
     * @param array $params
     * @param resource $curlHandler
     */
    protected function setRequestParams(array $params, $curlHandler = null)
    {
        $curlHandler = $curlHandler ? $curlHandler : $this->curlHandler;
        foreach ($params as $key => $value) {
            $const = constant('CURLOPT_' . strtoupper($key));
            if (!is_null($const)) {
                curl_setopt($curlHandler, $const, $value);
            }
        }
    }

    /**
     * Run single cURL request and return result
     * @param resource $curlHandler
     * @throws EllipticsException
     * @return mixed
     */
    protected function executeRequest($curlHandler = null)
    {
        $curlHandler = $curlHandler ? $curlHandler : $this->curlHandler;
        $requestResult = curl_exec($curlHandler);
        return $this->extractCurlRequestResult($curlHandler, $requestResult);
    }

    /**
     * Run multithreaded cURL requests and return result
     * @param resource $multiCurlHandler
     * @param array $curlHandlers
     * @return array
     */
    protected function executeMultipleRequests($multiCurlHandler, array $curlHandlers)
    {
        $result = array();
        $runningRequests = null;
        do {
            curl_multi_exec($multiCurlHandler, $runningRequests);
        } while($runningRequests > 0);
        foreach($curlHandlers as $fileId => $curlHandler) {
            $requestResult = curl_multi_getcontent($curlHandler);
            $result[$fileId] = $this->extractCurlRequestResult($curlHandler, $requestResult);
        }
        return $result;
    }

    /**
     * Extract cURL request result
     * @param resource $curlHandler
     * @param mixed $executeResult
     * @return mixed
     * @throws EllipticsException
     */
    protected function extractCurlRequestResult($curlHandler, $executeResult)
    {
        $executeResultInfo = curl_getinfo($curlHandler);
        if (curl_errno($curlHandler)) {
            throw new EllipticsException("Elliptics cURL error: " . curl_error($curlHandler), curl_errno($curlHandler));
        }
        if ($executeResultInfo['http_code'] != '200') {
            Yii::log("Elliptics warning: get \"{$executeResultInfo['http_code']}\" code while fetching \"{$executeResultInfo['url']}\".", CLogger::LEVEL_WARNING, get_class($this));
        }
        if ($executeResultInfo['http_code'] == '404') {
            return false;
        } elseif (empty($executeResult)) {
            return true;
        }
        return $executeResult;
    }

    /**
     * Extracts upload request result from XML-string returned by the Elliptics
     * @param string $requestResult
     * @return bool
     */
    protected function extractUploadRequestResult($requestResult)
    {
        if($requestResult) {
            $xmlResult = simplexml_load_string($requestResult);
            if(!empty($xmlResult->written) && intval($xmlResult->written) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Determines storage file identifier
     * @param mixed $file
     * @return string
     * @throws EllipticsException
     */
    protected function getStorageFileId($file)
    {
        if (is_array($file)) {
            if (!file_exists($file['path'])) {
                throw new EllipticsException("File \"{$file['path']}\" does not exists.");
            }
            $fileInfo = pathinfo($file['path']);
            $storageFileId = isset($file['storageFileId']) ? $file['storageFileId'] : $fileInfo['basename'];
        } else {
            if(!file_exists($file)) {
                throw new EllipticsException("File \"$file\" does not exists.");
            }
            $fileInfo = pathinfo($file);
            $storageFileId = $fileInfo['basename'];
        }
        return $storageFileId;
    }

    /**
     * Return file contents
     * @param mixed $file
     * @return string
     */
    protected function getFileContents($file)
    {
        $filePath = $this->getFilePath($file);
        return file_get_contents($filePath, true);
    }

    /**
     * Determine path to file
     * @param mixed $file
     * @return string
     * @throws EllipticsException
     */
    protected function getFilePath($file)
    {
        if(is_array($file)) {
            if(!isset($file['path'])) {
                throw new EllipticsException("Description of file does not contains \"path\" parameter.");
            }
            $filePath = $file['path'];
        } else {
            $filePath = $file;
        }
        return $filePath;
    }

}
