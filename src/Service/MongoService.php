<?php

namespace Athenea\Mongo\Service;

use Athenea\Mongo\Subscriber\MongoQuerySubscriber;
use MongoDB\BSON\ObjectId;
use MongoDB\Client;
use MongoDB\Database;
use MongoDB\GridFS\Bucket;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Stopwatch\Stopwatch;

use function MongoDB\Driver\Monitoring\addSubscriber;
use function MongoDB\Driver\Monitoring\removeSubscriber;

/**
 * Service to interact with MongoDB in a simplified way
 */
class MongoService
{
    /**
     * MongoDB client instance
     */
    public Client $mongoClient;

    // Optional subscriber for MongoDB query logging
    private ?MongoQuerySubscriber $subscriber = null;

    /**
     * Constructor to initialize the MongoDB client and optionally add a logging subscriber
     *
     * @param string $url MongoDB URL
     * @param string $defaultDb Default database to connect
     * @param bool $log Enable logging of MongoDB queries
     * @param ?LoggerInterface $logger Symfony logger
     */
    public function __construct(
        private string $url,
        private string $defaultDb,
        private bool $log = false,
        private ?LoggerInterface $logger = null,
        private ?Stopwatch $stopwatch = null,
    )
    {
        $this->mongoClient = new Client($url);
        if ($log && $this->logger) {
            $this->subscriber = new MongoQuerySubscriber($logger, $stopwatch);
            addSubscriber($this->subscriber);
        } 
    }

    /**
     * Get the MongoDB connection URL
     *
     * @return string MongoDB URL
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Get the MongoDB client instance
     *
     * @return Client MongoDB client
     */
    public function getClient(): Client
    {
        return $this->mongoClient;
    }

    /**
     * Select a specific collection in a database
     * 
     * @param string $collection Collection name
     * @param string|null $db Database name, defaults to the service's default database
     */
    public function selectCollection(string $collection, ?string $db = null)
    {
        if (is_null($db)) $db = $this->defaultDb;
        return $this->mongoClient->selectCollection($db, $collection);
    }

    /**
     * Get the default database instance
     * 
     * @return Database Default database
     */
    public function getDefaultDb()
    {
        return $this->mongoClient->selectDatabase($this->defaultDb);
    }

    /**
     * Upload a file to GridFS using a base64-encoded string
     * 
     * @param string $filename Name of the file
     * @param string $base64 Base64 binary representation of the file
     * @param array $metadata Metadata to attach to the file
     * @param array $options Options for GridFS upload
     * @return ObjectId ID of the uploaded file
     */
    public function uploadBase64File(string $filename, string $base64, array $metadata = [], array $options = []): ObjectId
    {
        $options['metadata'] = $metadata;
        $stream = fopen("data://$base64", 'r');
        return $this->gridFsBucket()->uploadFromStream($filename, $stream, $options);
    }

    /**
     * Get the GridFS bucket for the default database
     * 
     * @return Bucket GridFS bucket
     */
    public function gridFsBucket(): Bucket
    {
        return $this->getDefaultDb()->selectGridFSBucket();
    }

    /**
     * Mark a file as deleted and remove it from GridFS
     * 
     * @param ObjectId $id File ID to delete
     */
    public function deleteFile(ObjectId $id)
    {
        // Mark file metadata as deleted
        $this->gridFsBucket()->getFilesCollection()->updateOne(['_id' => $id], ['$set' => ['metadata.deleted' => true]]);
        // Physically delete the file from GridFS
        $this->gridFsBucket()->delete($id);
    }

    /**
     * Generate a Symfony response to download a file from GridFS
     * 
     * Supports 'range' parameter to enable partial content delivery (useful for video streaming)
     * @param Request $request Symfony request to download the file
     * @param ObjectId $fileId ID of the file to download
     * @param string $mimeType MIME type of the file to download
     * @return Response Symfony response for file download
     */
    public function mongoBinaryFileResponse(Request $request, ObjectId $fileId, string $mimeType): Response
    {
        $stream = $this->gridFsBucket()->openDownloadStream($fileId);
        $metadata = $this->gridFsBucket()->getFileDocumentForStream($stream);
        
        // Handle 'range' requests for partial downloads
        $range = $request->headers->get('range', null);
        $start = null;
        $end = null;
        if ($range) {
            $parts = explode("bytes=", $range);
            $range = $parts[1] ?? null;
            if (!is_null($range)) {
                $parts = explode('-', $range);
                if (sizeof($parts) == 2) {
                    $start = trim($parts[0]) !== "" ? intval($parts[0]) : null;
                    $end = trim($parts[1]) !== "" ? intval($parts[1]) : null;
                }
            }
        }

        $response = new Response();
        $contentLength = $metadata->length;

        // Respond to HEAD requests without content, just headers
        if ($request->getMethod() === "HEAD") {
            $response->headers->set("accept-ranges", "bytes");
            $response->headers->set("content-length", $contentLength);
            return $response;
        }

        // Calculate content length based on range headers
        $retrievedLength = $contentLength;
        if (!is_null($start) && !is_null($end)) $retrievedLength = ($end + 1) - $start;
        else if (!is_null($start)) $retrievedLength = $contentLength - $start;
        else if (!is_null($end)) $retrievedLength = ($end + 1);

        $statusCode = (!is_null($start) || !is_null($end)) ? 206 : 200;
        $response->setStatusCode($statusCode);
        $response->headers->set('content-type', $mimeType);
        $response->headers->set('content-length', $retrievedLength);

        if (!is_null($range)) {
            $bytesFirst = $start ?? 0;
            $bytesLast = $end ?? ($contentLength - 1);
            $response->headers->set('accept-ranges', "bytes");
            $response->headers->set('content-range', "bytes $bytesFirst-$bytesLast/$contentLength");
        }

        // Set content and return response
        $contents = stream_get_contents($stream, $retrievedLength, $start ?? 0);
        $response->setContent($contents);
        return $response;
    }

    /**
     * Upload a file with specific metadata to GridFS
     *
     * @param string $name File name
     * @param resource $file File resource to upload
     * @param string $mime MIME type
     * @param string $app Application name associated with the file
     * @param string $tag File tag for categorization
     * @param string $user User associated with the file
     * @return ObjectId|null ID of the uploaded file
     */
    public function uploadFile(string $name, $file, string $mime, string $app, string $tag, string $user): ?ObjectId
    {
        return $this->gridFsBucket()->uploadFromStream($name, $file, [
            'metadata' => [
                'app' => $app,
                'tag' => $tag,
                'user' => $user,
                'mime' => $mime
            ]
        ]);
    }

    /**
     * Retrieve metadata for a specific file
     *
     * @param ObjectId $id File ID
     * @return array|null File metadata document
     */
    public function fileMetadata(ObjectId $id)
    {
        return $this->gridFsBucket()->findOne(['_id' => $id], options: ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
    }

    /**
     * Disable logging of MongoDB queries
     */
    public function disableLogging()
    {
        removeSubscriber($this->subscriber);
    }

    /**
     * Enable logging of MongoDB queries
     */
    public function enableLogging()
    {
        addSubscriber($this->subscriber);
    }
}