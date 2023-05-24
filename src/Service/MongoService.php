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

/**
 * Servei per interectuar amb mongoDB de forma senzilla
 */
class MongoService
{

    /**
     * Client de mongo
     */
    public Client $mongoClient;

    /**
     * @param string $url url de mongo
     * @param string $defaultDb base de dades a connectar-se per defecte
     * @param string $log si cal fer logging de les queries de mongo o no
     * @param ?LoggerInterface $logger Logger de symfony
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
        if($log && $this->logger) addSubscriber(new MongoQuerySubscriber($logger, $stopwatch));
    }

    /**
     * Retorna la url de connexió a mongo
     *
     * @return string la url de mongo
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    public function getClient(): Client
    {
        return $this->mongoClient;
    }

    /**
     * Seleccionar una col·lecció d'una BBDD
     * 
     * @param string $collection Nom de la col·lecció
     * @param string $db Nom de la bbdd, per defecte defaultDb del servei
     */
    public function selectCollection(string $collection, ?string $db = null){
        if(is_null($db)) $db = $this->defaultDb;
        return $this->mongoClient->selectCollection($db, $collection);
    }

    /**
     * Retorna la BBDD per defecte
     * 
     * @return Database default DB
     */
    public function getDefaultDb(){
        return $this->mongoClient->selectDatabase($this->defaultDb);
    }

    /**
     * Puja un fitxer en base64 a GridFS
     * 
     * @param string $filename nom del fitxer
     * @param string $base64 representació binària en base64 del fitxer
     * @param array $metadata metadata a afegir al fitxer
     * @param array $options opcions a passar a gridFS
     * @return ObjectId id del fitxer inserit
     */
    public function uploadBase64File(string $filename, string $base64, array $metadata = [], array $options = []): ObjectId
    {
        $options['metadata'] = $metadata;
        $stream = fopen("data://$base64",'r');
        return $this->gridFsBucket()->uploadFromStream($filename, $stream, $options);
    }

    /**
     * Obté el contenidor de GridFS de la base de dades per defecte
     * 
     * @return Bucket contenidor de gridFS
     */
    public function gridFsBucket(): Bucket
    {
        return $this->getDefaultDb()->selectGridFSBucket();
    }

    /**
     * Elimina un fitxer de gridFS
     * 
     * @param ObjectId $id id de fitxer a eliminar
     */
    public function deleteFile(ObjectId $id){
        $this->gridFsBucket()->getFilesCollection()->updateOne(['_id' => $id], ['$set' => ['metadata.deleted' => true]]);
        $this->gridFsBucket()->delete($id);
    }

    /**
     * Retorna una resposta de symfony per descarregar un fitxer de gridFS
     * 
     * Admet el paràmetre 'range' per descarregar el binari per parts (ho usen navegadors moderns en videos i fitxers)
     * @param Request $request petició de symfony per descarregar el fitxer
     * @param ObjectId $fileId id del fitxer a descarregar
     * @param string $mimeType tipus MIME del fitxer a descarregar
     * @return Response resposta de symfony per descarregar el fitxer
     */
    public function mongoBinaryFileResponse(Request $request, ObjectId $fileId, string $mimeType): Response
    {
        $stream = $this->gridFsBucket()->openDownloadStream($fileId);
        $metadata =  $this->gridFsBucket()->getFileDocumentForStream($stream);
        $range = $request->headers->get('range', null);
        $start = null;
        $end = null;
        if($range){
            $parts = explode("bytes=", $range);
            $range = $parts[1] ?? null;
            if(!is_null($range)){
                $parts = explode('-', $range);
                if(sizeof($parts) == 2){
                    $start = trim($parts[0]);
                    if($start === "") $start = null;
                    else $start = intval($start);
                    $end = trim($parts[1]);
                    if($end === "") $end = null;
                    else $end = intval($end);
                }
            }
        }
        
        $response = new Response();
        $contentLenght = $metadata->length;
        if ($request->getMethod() === "HEAD") {
            $response->headers->set("accept-ranges", "bytes");
            $response->headers->set("content-length", $contentLenght);
            return $response;
        }

        $retrievedLength = null;
        if(!is_null($start) && !is_null($end)) $retrievedLength = ($end + 1) - $start;
        else if(!is_null($start)) $retrievedLength = $contentLenght - $start;
        else if(!is_null($end)) $retrievedLength = ($end + 1);
        else $retrievedLength = $contentLenght;

        $statusCode = ! is_null($start) || ! is_null($end) ? 206 : 200;
        $response->setStatusCode($statusCode);
        $response->headers->set('content-type', $mimeType);
        $response->headers->set('content-length', $retrievedLength);
        if(!is_null($range)){
            $bytesFirst = ($start ?? 0);
            $bytesLast = ($end ?? ($contentLenght - 1));
            $response->headers->set('accept-ranges', "bytes");
            $response->headers->set('content-range', "bytes $bytesFirst-$bytesLast/$contentLenght");
        }
        $offset = 0;
        $length = $contentLenght;
        if(!is_null($start)) $offset = $start;
        if(!is_null($end)) $length = $end + 1;
        $contents = stream_get_contents($stream, $length, $offset);
        $response->setContent($contents);
        return $response;
    }

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

    public function fileMetadata(ObjectId $id){
        $doc = $this->gridFsBucket()->findOne(['_id' => $id], options: ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
        return $doc;
    }

}
