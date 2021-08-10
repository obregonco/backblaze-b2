<?php

namespace obregonco\B2;

use obregonco\B2\Exceptions\CacheException;
use obregonco\B2\Exceptions\NotFoundException;
use obregonco\B2\Exceptions\ValidationException;
use obregonco\B2\Http\Client as HttpClient;
use Illuminate\Cache\CacheManager;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;

class Client
{
    protected $accountId;

    /**
     * @var \Illuminate\Contracts\Cache\Repository
     */
    protected $cache;

    protected string $authToken;
    protected string $apiUrl = '';
    protected string $downloadUrl;
    protected $recommendedPartSize;

    protected $authorizationValues;

    protected $client;

    public int $version = 1;

    public string $cacheParentDir = __DIR__;

    /**
     * If you setup CNAME records to point to backblaze servers (for white-label service)
     * assign this property with the equivalent URLs
     * ['f0001.backblazeb2.com' => 'alias01.mydomain.com']
     * @var array
     */
    public array $domainAliases = [];

    /**
     * Lower limit for using large files upload support. More information:
     * https://www.backblaze.com/b2/docs/large_files.html. Default: 3 GB
     * Files larger than this value will be uploaded in multiple parts.
     *
     * @var int
     */
    public int $largeFileLimit = 3000000000;

    /**
     * Seconds to remeber authorization
     * @var int
     */
    public int $authorizationCacheTime = 60;

    /**
     * Client constructor. Accepts the account ID, application key and an optional array of options.
     * @param string $accountId
     * @param array $authorizationValues
     * @param array $options
     * @throws CacheException
     */
    public function __construct(string $accountId, array $authorizationValues, array $options = [])
    {
        $this->accountId = $accountId;

        if (!isset($authorizationValues['keyId']) or empty($authorizationValues['keyId'])) {
            $authorizationValues['keyId'] = $accountId;
        }

        if (empty($authorizationValues['keyId']) or empty($authorizationValues['applicationKey'])) {
            throw new \Exception('Please provide "keyId" and "applicationKey"');
        }


        if (isset($options['client'])) {
            $this->client = $options['client'];
        } else {
            $this->client = new HttpClient(['exceptions' => false]);
        }

        if (isset($options['cacheParentDir'])) {
            $this->cacheParentDir = $options['cacheParentDir'];
        }

        // initialize cache
        $this->createCacheContainer();

        $this->authorizationValues = $authorizationValues;

        $this->authorizeAccount(false);
    }

    private function createCacheContainer(): void
    {
        if (!file_exists($this->cacheParentDir . '/Cache')) {
            mkdir($this->cacheParentDir . '/Cache', 0700, true);
        }

        $container = new Container();
        $container['config'] = [
            'cache.default' => 'file',
            'cache.stores.file' => [
                'driver' => 'file',
                'path' => $this->cacheParentDir . '/Cache',
            ],
        ];
        $container['files'] = new Filesystem;

        try {
            $cacheManager = new CacheManager($container);
            $this->cache = $cacheManager->store();
        } catch (\Exception $e) {
            throw new CacheException(
                $e->getMessage()
            );
        }
    }

    /**
     * Create a bucket with the given name and type.
     *
     * @param array $options
     * @return Bucket
     * @throws ValidationException
     */
    public function createBucket(array $options): Bucket
    {
        if (!in_array($options['BucketType'], [Bucket::TYPE_PUBLIC, Bucket::TYPE_PRIVATE])) {
            throw new ValidationException(
                sprintf('Bucket type must be %s or %s', Bucket::TYPE_PRIVATE, Bucket::TYPE_PUBLIC)
            );
        }

        $response = $this->request('POST', '/b2_create_bucket', [
            'json' => [
                'accountId' => $this->accountId,
                'bucketName' => $options['BucketName'],
                'bucketType' => $options['BucketType'],
            ],
        ]);

        return new Bucket($response);
    }

    /**
     * Updates the type attribute of a bucket by the given ID.
     *
     * @param array $options
     * @return Bucket
     * @throws ValidationException
     */
    public function updateBucket(array $options): Bucket
    {
        if (!in_array($options['BucketType'], [Bucket::TYPE_PUBLIC, Bucket::TYPE_PRIVATE])) {
            throw new ValidationException(
                sprintf('Bucket type must be %s or %s', Bucket::TYPE_PRIVATE, Bucket::TYPE_PUBLIC)
            );
        }

        if (!isset($options['BucketId']) && isset($options['BucketName'])) {
            $options['BucketId'] = $this->getBucketIdFromName($options['BucketName']);
        }

        $response = $this->request('POST', '/b2_update_bucket', [
            'json' => [
                'accountId' => $this->accountId,
                'bucketId' => $options['BucketId'],
                'bucketType' => $options['BucketType'],
            ],
        ]);

        return new Bucket($response);
    }

    /**
     * Returns a list of bucket objects representing the buckets on the account.
     *
     * @return Bucket[]
     */
    public function listBuckets(bool $refresh = false): array
    {
        $cacheKey = 'B2-SDK-Buckets';
        $bucketsObj = [];
        if (!$this->cache->has($cacheKey)) {
            $refresh = true;
        }
        if ($refresh === true) {
            $buckets = $this->request('POST', '/b2_list_buckets', [
                'json' => [
                    'accountId' => $this->accountId,
                ],
            ])['buckets'];
            $this->cache->set($cacheKey, $buckets, 10080);
        } else {
            $buckets = $this->cache->get($cacheKey);
        }

        foreach ($buckets as $bucket) {
            $bucketsObj[] = new Bucket($bucket);
        }

        return $bucketsObj;
    }

    /**
     * Deletes the bucket identified by its ID.
     *
     * @param array $options
     * @return bool
     */
    public function deleteBucket(array $options): bool
    {
        if (!isset($options['BucketId']) && isset($options['BucketName'])) {
            $options['BucketId'] = $this->getBucketIdFromName($options['BucketName']);
        }

        $this->request('POST', '/b2_delete_bucket', [
            'json' => [
                'accountId' => $this->accountId,
                'bucketId' => $options['BucketId'],
            ],
        ]);

        return true;
    }

    /**
     * Uploads a file to a bucket and returns a File object.
     *
     * @param array $options
     * @return File
     */
    public function upload(array $options): File
    {
        // Clean the path if it starts with /.
        if (str_starts_with($options['FileName'], '/')) {
            $options['FileName'] = ltrim($options['FileName'], '/');
        }

        if (!isset($options['BucketId']) && isset($options['BucketName'])) {
            $options['BucketId'] = $this->getBucketIdFromName($options['BucketName']);
        }

        if (!isset($options['FileLastModified'])) {
            $options['FileLastModified'] = round(microtime(true) * 1000);
        }

        if (!isset($options['FileContentType'])) {
            $options['FileContentType'] = 'b2/x-auto';
        }

        list($options['hash'], $options['size']) = $this->getFileHashAndSize($options['Body']);

        if ($options['size'] <= $this->largeFileLimit && $options['size'] <= $this->recommendedPartSize) {
            return $this->uploadStandardFile($options);
        } else {
            return $this->uploadLargeFile($options);
        }
    }

    /**
     * @param $bucket
     * @param $path
     * @param int $validDuration
     * @return string
     */
    public function getDownloadAuthorization($bucket, string $path, int $validDuration = 60): string
    {
        if ($bucket instanceof Bucket) {
            $bucketId = $bucket->getId();
        } else {
            $bucketId = $bucket;
        }

        $response = $this->request('POST', '/b2_get_download_authorization', [
            'json' => [
                'bucketId' => $bucketId,
                'fileNamePrefix' => $path,
                'validDurationInSeconds' => $validDuration,
            ],
        ]);

        return $response['authorizationToken'];
    }

    /**
     * @param Bucket|string $bucket
     * @param string $filePath
     * @param bool $appendToken
     * @param int $tokenTimeout
     * @return string
     */
    public function getDownloadUrl($bucket, string $filePath, bool $appendToken = false, int $tokenTimeout = 60)
    {
        if (!$bucket instanceof Bucket) {
            $bucket = $this->getBucketFromId($bucket);
        }

        $path = $this->downloadUrl . '/file/' . $bucket->getName() . '/' . $filePath;

        if ($appendToken) {
            $path .= '?Authorization='
                . $this->getDownloadAuthorization($bucket, dirname($filePath) . '/', $tokenTimeout);
        }

        return strtr($path, $this->domainAliases);
    }

    /**
     * @param File $file
     * @param bool $appendToken
     * @param int $tokenTimeout
     * @return string
     */
    public function getDownloadUrlForFile(File $file, bool $appendToken = false, int $tokenTimeout = 60): string
    {
        return $this->getDownloadUrl($file->getBucketId(), $file->getFileName(), $appendToken, $tokenTimeout);
    }

    /**
     * Download a file from a B2 bucket.
     *
     * @param array $options
     * @return bool|mixed|string
     */
    public function download(array $options)
    {
        $requestUrl = null;
        $requestOptions = [
            'sink' => $options['SaveAs'] ?? fopen('php://temp', 'w'),
        ];

        if (isset($options['FileId'])) {
            $requestOptions['query'] = ['fileId' => $options['FileId']];
            $requestUrl = $this->downloadUrl . '/b2api/v1/b2_download_file_by_id';
        } else {
            if (!isset($options['BucketName']) && isset($options['BucketId'])) {
                $options['BucketName'] = $this->getBucketNameFromId($options['BucketId']);
            }

            $requestUrl = sprintf('%s/file/%s/%s', $this->downloadUrl, $options['BucketName'], $options['FileName']);
        }

        if (isset($options['stream'])) {
            $requestOptions['stream'] = $options['stream'];
            $response = $this->request('GET', $requestUrl, $requestOptions, false);
        } else {
            $response = $this->request('GET', $requestUrl, $requestOptions, false);
        }

        return isset($options['SaveAs']) ? true : $response;
    }

    public function accelRedirectData(array $options): array
    {
        $parsed = parse_url($this->downloadUrl);

        return [
            'host' => $parsed['host'],
            'query' => sprintf("fileId=%s", $options['FileId']),
        ];
    }

    /**
     * Retrieve a collection of File objects representing the files stored inside a bucket.
     *
     * @param array $options
     * @return File[]
     */
    public function listFilesFromArray(array $options): array
    {
        // if FileName is set, we only attempt to retrieve information about that single file.
        $fileName = !empty($options['FileName']) ? $options['FileName'] : null;

        $nextFileName = null;
        $maxFileCount = 1000;
        $files = [];

        if (!isset($options['BucketId']) && isset($options['BucketName'])) {
            $options['BucketId'] = $this->getBucketIdFromName($options['BucketName']);
        }

        if ($fileName) {
            $nextFileName = $fileName;
            $maxFileCount = 1;
        }

        // B2 returns, at most, 1000 files per "page". Loop through the pages and compile an array of File objects.
        while (true) {
            $response = $this->request('POST', '/b2_list_file_names', [
                'json' => [
                    'bucketId' => $options['BucketId'],
                    'startFileName' => $nextFileName,
                    'maxFileCount' => $maxFileCount,
                ],
            ]);

            foreach ($response['files'] as $file) {
                // if we have a file name set, only retrieve information if the file name matches
                if (!$fileName || ($fileName === $file['fileName'])) {
                    $files[] = new File($file);
                }
            }

            if ($fileName || $response['nextFileName'] === null) {
                // We've got all the files - break out of loop.
                break;
            }

            $nextFileName = $response['nextFileName'];
        }

        return $files;
    }

    /**
     * @param Bucket $bucket
     * @param string $startFileName
     * @param string $prefix
     * @param string $delimiter
     * @param int $maxFileCount
     * @return File[]
     */
    public function listFiles(
        Bucket $bucket,
        string $startFileName = '',
        string $delimiter = '',
        int $maxFileCount = 1
    ): array {
        $files = [];

        $nextFileName = '';

        // B2 returns, at most, 1000 files per "page". Loop through the pages and compile an array of File objects.
        while (true) {
            $params = [
                'bucketId' => $bucket->getId(),
                'startFileName' => $nextFileName,
                'maxFileCount' => $maxFileCount,
                'prefix' => $startFileName,
            ];
            if (!empty($delimiter)) {
                $params['delimiter'] = $delimiter;
            }
            $response = $this->request('POST', '/b2_list_file_names', [
                'json' => $params,
            ]);

            foreach ($response['files'] as $file) {
                if (substr($file['fileName'], -1, 1) === '/') {
                    // Omitir directorios
                    continue;
                }
                $files[] = new File($file);
            }

            if ($response['nextFileName'] === null) {
                // We've got all the files - break out of loop.
                break;
            }

            $nextFileName = $response['nextFileName'];
        }

        return $files;
    }

    /**
     * Test whether a file exists in B2 for the given bucket.
     *
     * @param array $options
     * @return boolean
     */
    public function fileExistsFromArray(array $options): bool
    {
        $files = $this->listFilesFromArray($options);

        return !empty($files);
    }

    /**
     * Test whether a file exists in B2 for the given bucket.
     *
     * @param array $options
     * @return bool
     */
    public function fileExists(Bucket $bucket, string $fileName): bool
    {
        $files = $this->listFiles($bucket, $fileName);

        return !empty($files);
    }

    /**
     * Returns a single File object representing a file stored on B2.
     *
     * @param array $options
     * @return File
     * @throws NotFoundException If no file id was provided and BucketName + FileName does not resolve to a file, a NotFoundException is thrown.
     */
    public function getFileFromArray(array $options): File
    {
        if (isset($options['FileId'])) {
            return $this->getFileFromFileId($options['FileId']);
        }

        $bucket = new Bucket([
            'BucketId' => @$options['BucketId'],
            'BucketName' => @$options['BucketName'],
        ]);

        return $this->getFile($bucket, $options['FileName']);
    }

    /**
     * This is an alias of getFileFromFileName function
     * @param Bucket $bucket
     * @param string $fileName
     * @return ?File
     */
    public function getFile(Bucket $bucket, string $fileName): ?File
    {
        return $this->getFileFromFileName($bucket, $fileName);
    }

    /**
     * @param string $fileId
     * @return File
     */
    public function getFileFromFileId(string $fileId): File
    {
        $response = $this->request('POST', '/b2_get_file_info', [
            'json' => [
                'fileId' => $fileId,
            ],
        ]);

        return new File($response);
    }

    /**
     * Deletes the file identified by ID from Backblaze B2.
     *
     * @param array $options
     * @return bool
     */
    public function deleteFileFromArray(array $options): bool
    {
        if (!isset($options['FileName'])) {
            $file = $this->getFileFromArray($options);

            $options['FileName'] = $file->getFileName();
        }

        if (!isset($options['FileId']) && isset($options['BucketName']) && isset($options['FileName'])) {
            $file = $this->getFileFromArray($options);

            $options['FileId'] = $file->getFileId();
        }

        $this->request('POST', '/b2_delete_file_version', [
            'json' => [
                'fileName' => $options['FileName'],
                'fileId' => $options['FileId'],
            ],
        ]);

        return true;
    }

    /**
     * Deletes the file identified by ID from Backblaze B2.
     *
     * @param array $options
     * @return bool
     */
    public function deleteFile(File $file): bool
    {
        $this->request('POST', '/b2_delete_file_version', [
            'json' => [
                'fileName' => $file->getFileName(),
                'fileId' => $file->getFileId(),
            ],
        ]);

        return true;
    }

    /**
     * Maps the provided bucket name to the appropriate bucket ID.
     *
     * @param string $name
     * @return string|null
     */
    public function getBucketIdFromName(string $name): ?string
    {
        $bucket = $this->getBucketFromName($name);

        if ($bucket instanceof Bucket) {
            return $bucket->getId();
        }

        return null;
    }

    /**
     * Maps the provided bucket ID to the appropriate bucket name.
     *
     * @param string $id
     * @return string|null
     */
    public function getBucketNameFromId(string $id): ?string
    {
        $bucket = $this->getBucketFromId($id);

        if ($bucket instanceof Bucket) {
            return $bucket->getName();
        }

        return null;
    }

    /**
     * @param string $bucketId
     * @return ?Bucket
     */
    public function getBucketFromId(string $bucketId): ?Bucket
    {
        $buckets = $this->listBuckets();

        foreach ($buckets as $bucket) {
            if ($bucket->getId() === $bucketId) {
                return $bucket;
            }
        }

        return null;
    }

    /**
     * @param string $name
     * @return ?Bucket
     */
    public function getBucketFromName(string $name): ?Bucket
    {

        $buckets = $this->listBuckets();

        foreach ($buckets as $bucket) {
            if ($bucket->getName() === $name) {
                return $bucket;
            }
        }

        return null;
    }

    protected function getFileFromFileName(Bucket $bucket, string $fileName): ?File
    {
        if (empty($bucket->getId())) {
            throw new \Exception('BucketId has not been set');
        }

        /** @var File[] $files */
        $files = $this->listFiles($bucket, $fileName);

        foreach ($files as $file) {
            if ($file->getFileName() === $fileName) {
                return $file;
            }
        }

        return null;
    }

    protected function getFileIdFromBucketAndFileName(string $bucketName, string $fileName): ?string
    {
        $files = $this->listFilesFromArray([
            'BucketName' => $bucketName,
            'FileName' => $fileName,
        ]);

        foreach ($files as $file) {
            if ($file->getFileName() === $fileName) {
                return $file->getFileId();
            }
        }

        return null;
    }

    /**
     * Calculate hash and size of file/stream. If $offset and $partSize is given return
     * hash and size of this chunk
     *
     * @param $data
     * @param int $offset
     * @param null $partSize
     * @return array
     */
    protected function getFileHashAndSize($data, int $offset = 0, $partSize = null): array
    {
        if (!$partSize) {
            if (is_resource($data)) {
                // We need to calculate the file's hash incrementally from the stream.
                $context = hash_init('sha1');
                hash_update_stream($context, $data);
                $hash = hash_final($context);
                // Similarly, we have to use fstat to get the size of the stream.
                $size = fstat($data)['size'];
                // Rewind the stream before passing it to the HTTP client.
                rewind($data);
            } else {
                // We've been given a simple string body, it's super simple to calculate the hash and size.
                $hash = sha1($data);
                $size = mb_strlen($data, '8bit');
            }
        } else {
            $dataPart = $this->getPartOfFile($data, $offset, $partSize);
            $hash = sha1($dataPart);
            $size = mb_strlen($dataPart, '8bit');
        }

        return array($hash, $size);
    }

    /**
     * Return selected part of file
     *
     * @param $data
     * @param int $offset
     * @param int $partSize
     * @return bool|string
     */
    protected function getPartOfFile($data, int $offset, int $partSize)
    {
        // Get size and hash of one data chunk
        if (is_resource($data)) {
            // Get data chunk
            fseek($data, $offset);
            $dataPart = fread($data, $partSize);
            // Rewind the stream before passing it to the HTTP client.
            rewind($data);
        } else {
            $dataPart = substr($data, $offset, $partSize);
        }
        return $dataPart;
    }

    /**
     * Upload single file (smaller than 3 GB)
     *
     * @param array $options
     * @return File
     */
    protected function uploadStandardFile(array $options = []): File
    {
        // Retrieve the URL that we should be uploading to.
        $response = $this->request('POST', '/b2_get_upload_url', [
            'json' => [
                'bucketId' => $options['BucketId'],
            ],
        ]);

        $uploadEndpoint = $response['uploadUrl'];
        $uploadAuthToken = $response['authorizationToken'];

        $response = $this->request('POST', $uploadEndpoint, [
            'headers' => [
                'Authorization' => $uploadAuthToken,
                'Content-Type' => $options['FileContentType'],
                'Content-Length' => $options['size'],
                'X-Bz-File-Name' => $options['FileName'],
                'X-Bz-Content-Sha1' => $options['hash'],
                'X-Bz-Info-src_last_modified_millis' => $options['FileLastModified'],
            ],
            'body' => $options['Body'],
        ]);

        return new File($response);
    }

    /**
     * Upload large file. Large files will be uploaded in chunks of recommendedPartSize bytes (usually 100MB each)
     *
     * @param array $options
     * @return File
     */
    protected function uploadLargeFile(array $options): File
    {
        // Prepare for uploading the parts of a large file.
        $response = $this->request('POST', '/b2_start_large_file', [
            'json' => [
                'bucketId' => $options['BucketId'],
                'fileName' => $options['FileName'],
                'contentType' => $options['FileContentType'],
                /**
                 * 'fileInfo' => [
                 * 'src_last_modified_millis' => $options['FileLastModified'],
                 * 'large_file_sha1' => $options['hash']
                 * ]
                 **/
            ],
        ]);
        $fileId = $response['fileId'];

        $partsCount = ceil($options['size'] / $this->recommendedPartSize);

        $hashParts = [];
        for ($i = 1; $i <= $partsCount; $i++) {
            $bytesSent = ($i - 1) * $this->recommendedPartSize;
            $bytesLeft = $options['size'] - $bytesSent;
            $partSize = ($bytesLeft > $this->recommendedPartSize) ? $this->recommendedPartSize : $bytesLeft;

            // Retrieve the URL that we should be uploading to.
            $response = $this->request('POST', '/b2_get_upload_part_url', [
                'json' => [
                    'fileId' => $fileId,
                ],
            ]);

            $uploadEndpoint = $response['uploadUrl'];
            $uploadAuthToken = $response['authorizationToken'];

            list($hash, $size) = $this->getFileHashAndSize($options['Body'], $bytesSent, $partSize);
            $hashParts[] = $hash;

            $response = $this->request('POST', $uploadEndpoint, [
                'headers' => [
                    'Authorization' => $uploadAuthToken,
                    'X-Bz-Part-Number' => $i,
                    'Content-Length' => $size,
                    'X-Bz-Content-Sha1' => $hash,
                ],
                'body' => $this->getPartOfFile($options['Body'], $bytesSent, $partSize),
            ]);
        }

        // Finish upload of large file
        $response = $this->request('POST', '/b2_finish_large_file', [
            'json' => [
                'fileId' => $fileId,
                'partSha1Array' => $hashParts,
            ],
        ]);
        return new File($response);
    }

    /**
     * @param Key $key
     * @throws \Exception
     */
    public function createKey($key)
    {
        throw new \Exception(__FUNCTION__ . ' has not been implemented yet');
    }

    /**
     * Authorize the B2 account in order to get an auth token and API/download URLs.
     * @param bool $forceRefresh
     */
    public function authorizeAccount(bool $forceRefresh = false): void
    {
        $baseApiUrl = 'https://api.backblazeb2.com';
        $versionPath = '/b2api/v' . $this->version;

        if ($forceRefresh === true) {
            $this->cache->forget('B2-SDK-Authorization');
        }

        $response = $this->cache->remember('B2-SDK-Authorization', $this->authorizationCacheTime,
            function () use ($baseApiUrl, $versionPath) {
                return $this->request('GET', $baseApiUrl . $versionPath . '/b2_authorize_account', [
                    'auth' => [
                        $this->authorizationValues['keyId'],
                        $this->authorizationValues['applicationKey'],
                    ],
                ]);
            });

        $this->authToken = $response['authorizationToken'];
        $this->apiUrl = $response['apiUrl'] . $versionPath;
        $this->downloadUrl = $response['downloadUrl'];
        $this->recommendedPartSize = $response['recommendedPartSize'];
    }

    /**
     * Wrapper for $this->client->request
     * @param string $method
     * @param string $uri
     * @param array $options
     * @param bool $asJson
     * @return mixed|string
     */
    protected function request(string $method, string $uri = '', array $options = [], bool $asJson = true)
    {
        $headers = [];

        // Add Authorization token if defined
        if (isset($this->authToken)) {
            $headers['Authorization'] = $this->authToken;
        }

        $options = array_replace_recursive([
            'headers' => $headers,
        ], $options);

        $fullUri = $uri;

        if (!str_starts_with($uri, 'https://')) {
            $fullUri = $this->apiUrl . $uri;
        }

        $response = $this->client->request($method, $fullUri, $options);

        if ($asJson) {
            return json_decode($response->getBody(), true);
        }

        return $response->getBody();
    }
}
