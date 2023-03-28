<?php

namespace Athenea\Mongo\Serializer\MongoObjectId;

use MongoDB\BSON\ObjectId;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalitzador de symfony que converteix ObjectId de mongo en strings.
 * 
 * La funcionalitat ve de que els ObjectId es converteixen per defecte a la forma:
 * ['_id' => ['$oid' => "identificador_usuari"]]
 * 
 * quan voldriem que fos:
 * ['_id' => "identificador_usuari"]
 */
class MongoObjectIdNormalizer implements NormalizerInterface
{


    /**
     * @inheritdoc
     */
    public function normalize($id, string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        return $id->__toString();
    }

    /**
     * @inheritdoc
     */
    public function supportsNormalization(mixed $data, string $format = null, array $context = []): bool
    {
        return $data instanceof ObjectId;
    }
}