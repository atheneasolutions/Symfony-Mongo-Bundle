<?php

namespace Athenea\Mongo\Serializer\MongoObjectId;

use MongoDB\BSON\ObjectId;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * DeNormalitzador de symfony que converteix strings en mongo ObjectId.
 * 
 * La funcionalitat ve de que els ObjectId es converteixen per defecte a la forma:
 * ['_id' => ['$oid' => "identificador_usuari"]]
 * 
 * quan voldriem que fos:
 * ['_id' => "identificador_usuari"]
 */
class MongoObjectIdDenormalizer implements DenormalizerInterface
{


    public function __construct(
        private ?DenormalizerInterface $denormalizer = null
    )
    {
        
    }
    /**
     * @inheritdoc
     */
    public function supportsDenormalization(mixed $data, string $type, string $format = null, array $context = []): bool
    {
        return is_a($type, ObjectId::class, true);
    }

    /**
     * @inheritdoc
     */
    public function denormalize(mixed $data, string $type, string $format = null, array $context = []): mixed
    {
        if(is_array($data)){
            $id = $data['$oid'] ?? null;
            if(!is_null($id)) return new ObjectId($id);
        }
        if(is_string($data)) return new ObjectId($data);
        return $this->denormalizer?->denormalize($data, $type, $format, $context);
    }
}