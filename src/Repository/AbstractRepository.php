<?php

namespace Athenea\Mongo\Repository;

use Athenea\Mongo\Model\MongoBase;
use Athenea\Mongo\Service\MongoService;
use Athenea\MongoLib\Utils;
use DateTime;
use Exception;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\BSON\UTCDateTimeInterface;
use MongoDB\Collection;
use MongoDB\InsertManyResult;
use MongoDB\InsertOneResult;
use MongoDB\Operation\FindOneAndReplace;
use MongoDB\UpdateResult;
use ReflectionClass;
use Symfony\Component\Serializer\Annotation\DiscriminatorMap;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

use function Athenea\MongoLib\BSON\oid;
use function Athenea\Utils\Array\array_is_assoc;

/**
 * Repositori abstracte de les classes que hereden de {@see MongoBase}
 * 
 * Exposa la col·lecció de la classe que hereda de {@see MongoBase} així com mètodes per facilitar l'obtenció i modificació
 * d'objectes de la base de dades
 * 
 * @author Lluc Bové <lluc.bove@atheneasolutions.com>
 */
abstract class AbstractRepository
{
    public const UPDATEABLE = 'updateable';

    /**
     * Col·lecció de mongoDB de la classe
     */
    protected Collection $collection;

    protected ?DiscriminatorMap $discriminatorMap = null;
    protected bool $classIsAbstract = false;

    /**
     * @param MongoService $mongo servei de mongo per interectuar amb la BBDD
     */
    public function __construct(
        protected MongoService $mongo,
        protected NormalizerInterface $normalizer,
        protected DenormalizerInterface $denormalizer
    )
    {
        $class = $this->modelClass();
        if(! is_a($class, MongoBase::class, true)) throw new Exception("$class is not an instance of Athenea\Mongo\Model\MongoBase");
        $colName = $class::collectionName();
        $this->collection = $this->mongo->selectCollection($colName);

        $reflectionClass = new ReflectionClass($class);
        $discr = $reflectionClass->getAttributes(DiscriminatorMap::class)[0] ?? null;
        if($discr) $this->discriminatorMap = $discr->newInstance();
        $this->classIsAbstract = $reflectionClass->isAbstract();

    }

    /**
     * Obté la col·lecció de la classe que representa el repositori ({@see self::modelClass()})
     * 
     * @return Collection col·lecció de la classe que representa el repositori
     */
    public final function getCollection(): Collection
    {
        return $this->collection;
    }

    /**
     * Obté un objecte de la classe {@see self::modelClass()} per l'_id de mongo
     * @param string $id la representació hexadecimal de l'id de mongo
     * @return mixed l'objecte de la classe {@see self:modelClass} amb l'id proporcionat
     */
    public final function findById(string $id)
    {
        if(!preg_match("/[a-fA-F0-9]{24}/", $id)) return null;
        return $this->findByObjectId(oid($id));
    }

    /**
     * Obté un objecte de la classe {@see self::modelClass()} per l'_id de mongo
     * @param ObjectId $id la representació hexadecimal de l'id de mongo
     * @return mixed l'objecte de la classe {@see self:modelClass} amb l'id proporcionat
     */
    public final function findByObjectId(ObjectId $id)
    {
        return $this->findOne([ '_id' => $id ]);
    }

    /**
      * Obté els objectes de la classe {@see self::modelClass} aplicant els filtres especificats
     * 
     * @param array $filter filtre de mongo a aplicar
     * @param array $options opcions a passar a mongo
     * @return mixed els objectes de la classe {@see self:modelClass} que compleix els filtres passats
     */
    public final function find(array $filter = [], array $options = [])
    {
        $options = $this->mergeTypeMapOptions($options);
        $result =  $this->collection->find($filter, $options);
        return $this->discriminatorMap ? $this->bsonNormalizeIterable($result) : $result;
    }

    /**
     * Obté un objecte de la classe {@see self::modelClass} aplicant els filtres especificats
     * 
     * @param array $filter filtre de mongo a aplicar
     * @param array $options opcions a passar a mongo
     * @return mixed el primer objecte de la classe {@see self:modelClass} que compleix els filtres passats
     */
    public final function findOne(array $filter = [], array $options = []): ?MongoBase
    {
        $options = $this->mergeTypeMapOptions($options);
        $result =  $this->collection->findOne($filter, $options);
        return $this->discriminatorMap ? $this->bsonNormalizeGeneric($result) : $result;
    }

    /**
     * Actualitza el primer objecte que coincideix amb els filtres passats
     * 
     * @param array $filter filtre de mongo a aplicar
     * @param array $update objecte d'actualització a aplicar
     * @param array $options opcions a passar a mongo
     * @return UpdateResult resultat d'aplicar l'actualització
     */
    public final function updateOne(array $filter = [], array $update = [], array $options = []): UpdateResult
    {
        if(array_is_assoc($update)) $update['$set']['updated_at'] ??= Utils::now();
        else $update[] = ['$set' => ['updated_at' => '$$NOW']];
        return $this->collection->updateOne($filter, $update, $options);
    }


    /**
     * Actualitza els objectes que coincideixen amb els filtres passats
     * 
     * @param array $filter filtre de mongo a aplicar
     * @param array $update objecte d'actualització a aplicar
     * @param array $options opcions a passar a mongo
     * @return UpdateResult resultat d'aplicar l'actualització
     */
    public final function updateMany(array $filter = [], array $update = [], array $options = []): UpdateResult
    {
        if(array_is_assoc($update)) $update['$set']['updated_at'] ??= Utils::now();
        else $update[] = ['$set' => ['updated_at' => '$$NOW']];
        return $this->collection->updateMany($filter, $update, $options);
    }

    /**
     * Actualitza l'objecte
     * 
     * @param MongoBase $doc objecte a actualitzar
     * @param array $update objecte d'actualització a aplicar
     * @param array $options opcions a passar a mongo
     * @return UpdateResult resultat d'aplicar l'actualització
     */
    public final function updateDoc(MongoBase $doc, array $update = [], array $options = []): UpdateResult
    {
        return $this->updateOne(['_id' => $doc->getId()], $update, $options);
    }

    /**
     * Actualitza un document a partir d'una array de canvis
     * 
     * Aquest mètode serveix per actualitzar camps simples d'un document mongo.
     * Donada una array on les claus són els noms (en snake case) dels camps simles a actualitzar, i les claus són
     * els valors que cal canviar, s'aplicarà un update a aquests camps si existeixen en el document mongo i si
     * són del tipus 'updatable'. S'aplicaran els canvis al document de mongo, tant a BBDD com a l'objecte {@see MongoBase}
     * 
     * @param MongoBase $doc document a actualitzar
     * @param array<string, mixed> $changeArray diccionari amb els canvis a aplicar al document
     * @param array $options opcions a passar al client de mongo
     * @return UpdateResult resultat d'aplicar l'actualització
     */
    public final function updateWithChangeArray(MongoBase $doc, array $changeArray = [], array $options = []): UpdateResult
    {
        $this->denormalizer->denormalize($changeArray, $doc::class, null, [
            AbstractNormalizer::OBJECT_TO_POPULATE => $doc,
            AbstractNormalizer::GROUPS => self::UPDATEABLE
        ]);
        return $this->updateDoc($doc, ['$set' => $changeArray], $options);
    }

    /**
     * Inserir un document de mongo
     * 
     * @param array $doc document a inserir
     * @param array $options opcions a passar a mongo
     * @return InsertOneResult resultat d'aplicar l'inserció
     */
    public final function insertOne(?MongoBase $doc = null, array $options = []): InsertOneResult
    {
        return $this->collection->insertOne($doc, $options);
    }

    /**
     * Inserir varis documents de mongo
     * 
     * @param MongoBase[] $docs documents a inserir
     * @param array $options opcions a passar a mongo
     * @return InsertManyResult resultat d'aplicar l'inserció
     */
    public final function insertMany(array $docs = null, array $options = []): InsertManyResult
    {
        return $this->collection->insertMany($docs, $options);
    }


    /**
     * Troba un document i el substitueix (per defecte inserta si no el troba)
     * 
     * @param array $filter filtre per trobar el document a substituir
     * @param ?MongoBase $doc document pel qual es substituirà el trobat
     * @param array $options opcions a passar a mongo
     * @return ?MongoBase document després d'aplicar la substitució (mongo)
     */
    public final function findAndReplace(array $filter = [], ?MongoBase $doc = null, array $options = []): ?MongoBase
    {
        $options['upsert'] ??= true;
        $options['returnDocument'] ??= FindOneAndReplace::RETURN_DOCUMENT_AFTER;
        $options = $this->mergeTypeMapOptions($options);
        $result =  $this->collection->findOneAndReplace($filter, $doc, $options);
        return $this->discriminatorMap ? $this->bsonNormalizeGeneric($result) : $result;
    }

    private function bsonNormalizeGeneric($x): ?MongoBase
    {
        $class = $this->getDiscriminatorClass($x);
        $obj = new $class();
        $obj->bsonUnserialize($x);
        return $obj;
    }
    private function bsonNormalizeIterable($iterable){
        foreach($iterable as $i){
            yield $this->bsonNormalizeGeneric($i);
        } 
    }

    /**
     * Troba el document a mongo i el substitueix pel que es passa al paràmetre (per defecte inserta si no el troba)
     * 
     * @param ?MongoBase $doc document pel qual es substituirà el trobat
     * @param array $options opcions a passar a mongo
     * @return ?MongoBase document després d'aplicar la substitució (mongo)
     */
    public final function replace(?MongoBase $doc = null, array $options = []): ?MongoBase
    {
        return $this->findAndReplace(['_id' => $doc->getId()], $doc, $options);
    }

    /**
     * Busca el document a la base de dades i n'actualitza les propietats
     * 
     * @param MongoBase $doc l'ojbecte a actualitzar amb les dades de BBDD
     */
    public function reHydrate(MongoBase $doc, bool $bson = false)
    {
        if($bson){
            $updatedDoc = $this->collection->findOne(['_id' => $doc->getId()]);
            $doc->bsonUnserialize($updatedDoc);
        }
        else {
            $updatedDoc = $this->findById($doc->getId());
            if($updatedDoc){
                $normalized = $this->normalizer->normalize($updatedDoc, null);
                $this->denormalizer->denormalize($normalized, $doc::class, null, [AbstractNormalizer::OBJECT_TO_POPULATE => $doc] );
            }
        }
    }

    public function aggregate(array $pipeline, array $options = []){
        $this->mergeTypeMapOptions($options);
        $result =  $this->getCollection()->aggregate($pipeline, $options);
        foreach($result as $r){
            $n = $this->normalizeGeneric($r);
            $object = $this->bsonNormalizeGeneric($n);
            yield $object;
        }
    }

    protected function getDiscriminatorClass($data){
        if(!$this->discriminatorMap) return $this->modelClass();
        $property = $this->discriminatorMap->getTypeProperty();
        $field = $this->accesGeneric($data, $property);
        $class = $this->discriminatorMap->getMapping()[$field] ?? null;
        if(!$class && !$this->classIsAbstract) return $this->modelClass();
        return $class;
    }

    private function accesGeneric($data, $key){
        if(is_array($data)) return $data[$key] ?? null;
        if(is_object($data)) return $data->{$key} ?? null;
        return null;
    }


    /**
     * Elimina un document de mongo
     * 
     * @param MongoBase $doc document a eliminar
     * @param array $options opcions a passar al driver de Mongo
     */
    public function deleteDoc(MongoBase $doc, array $options = []){
        return $this->getCollection()->deleteOne(['_id' => $doc->getId()], $options);
    }

    /**
     * Retorna la classe que ha de representar el repository
     * exemple: UserRepository tornarà la classe User
     * 
     * @return string la classe que ha de representar el repository
     */
    public abstract static function modelClass(): string;


    protected static function normalize(\MongoDB\Model\BSONDocument $bSONDocument){
        $serialization = $bSONDocument->jsonSerialize();
        $newSerialization = [];
        foreach($serialization as $key => $value){
            if(is_a($value, \MongoDB\Model\BSONArray::class)) {
                $newSerialization[$key] = self::normalizeArray($value);
            }
            else if(is_a($value, \MongoDB\Model\BSONDocument::class)) {
                $newSerialization[$key] = self::normalize($value);
            }
            else if(is_a($value, UTCDateTimeInterface::class)){
                $newSerialization[$key] = $value->toDateTime();
            }
            else $newSerialization[$key] = $value;
        }
        return $newSerialization;
    }

    protected static function normalizeArray(\MongoDB\Model\BSONArray $bSONArray){
        $newSerialization = [];
        $serialization = $bSONArray->jsonSerialize();
        foreach($serialization as $value){
            if(is_a($value, \MongoDB\Model\BSONArray::class)) {
                $newSerialization[] = self::normalizeArray($value);
            }
            else if(is_a($value, \MongoDB\Model\BSONDocument::class)) {
                $newSerialization[] = self::normalize($value);
            }
            else if(is_a($value, UTCDateTimeInterface::class)){
                $newSerialization[] = $value->toDateTime();
            }
            else $newSerialization[] = $value;
        }
        return $newSerialization;
    }

    protected static function normalizeIterable($iterable){
        $newSerialization = [];
        foreach($iterable as $value){
            if(is_a($value, \MongoDB\Model\BSONArray::class)) {
                $newSerialization[] = self::normalizeArray($value);
            }
            else if(is_a($value, \MongoDB\Model\BSONDocument::class)) {
                $newSerialization[] = self::normalize($value);
            }
            else if(is_a($value, UTCDateTimeInterface::class)){
                $newSerialization[] = $value->toDateTime();
            }
            else $newSerialization[] = $value;
        }
        return $newSerialization;
    }



    protected static function normalizeGeneric($obj){
        if(!$obj) return null;
        if(is_a($obj, \MongoDB\Model\BSONDocument::class)) return self::normalize($obj);
        if(is_a($obj, \MongoDB\Model\BSONArray::class)) return self::normalizeArray($obj);
        if(is_a($obj, \MongoDB\Model\BSONIterator::class)) return self::normalizeIterable($obj);
        if(is_a($obj, UTCDateTimeInterface::class)) return $obj->toDateTime();
        return null;
    }

    /**
     * Afegeix el typeMap a les opcions de mongo proporcionades.
     * 
     * @see https://www.php.net/manual/en/mongodb.persistence.deserialization.php#mongodb.persistence.typemaps Documentació TypeMap
     * 
     * @param array $options opcions de mongo a afegir el typeMap
     * @return array les opcions amb el typeMap afegit
     */
    protected function mergeTypeMapOptions(array $options){
        $typeMap = $this->getTypeMap();
        return array_merge($typeMap, $options);
    }

    /**
     * Obté el typeMap de la classe {@see self::mongoDate}
     * 
     * @see https://www.php.net/manual/en/mongodb.persistence.deserialization.php#mongodb.persistence.typemaps Documentació TypeMap
     * 
     * @return array type map de la classe
     */
    protected function getTypeMap(): array
    {
        return [
            'typeMap' => [ 'root' => $this->discriminatorMap ? 'object' : $this->modelClass() ]
        ];
    }

    /**
     * Donat un Datetime obté la UTCDateTime de mongo corresponent
     * 
     * @param DateTime $date data a convertir
     * @return UTCDateTime Data de mongo que correspon a $date
     * @deprecated Fer servir el mètode de la llibreria Athenea\PHP-utils
     */
    protected function mongoDate(DateTime $date): UTCDateTime
    {
        return new UTCDateTime($date->getTimestamp() * 1000);
    }

    /**
     * Obté la UTCDateTime de mongo que representa la data actual
     * @return UTCDateTime data de mongo que representa la data actual
     * @deprecated Fer servir el mètode de la llibreria Athenea\PHP-utils
     */
    protected function mongoNow(): UTCDateTime
    {
        return $this->mongoDate(new DateTime());
    }
}
