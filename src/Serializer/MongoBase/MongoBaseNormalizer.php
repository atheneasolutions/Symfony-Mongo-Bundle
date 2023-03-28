<?php

namespace Athenea\Mongo\Serializer\MongoBase;

use Athenea\Mongo\Model\MongoBase;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer as AN;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalitzador d'objectes {@see MongoBase}.
 * 
 * Es normalitzen els atributs que tinguin el grup 'serializable' i prou.
 */
class MongoBaseNormalizer implements NormalizerInterface
{

    /**
     * Opció del normalitzador que indica si cal ignorar els efectes d'aquest
     */
    public const IGNORE = 'mongo_base_ignore';

    public const SERIALIZABLE = 'mongo_base_serializable';

    public function __construct(private ?NormalizerInterface $normalizer = null)
    {
    }

    /**
     * @inheritdoc
     */
    public function normalize($object, string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        // afegir 'serializable' als grups
        $groups = $context[AN::GROUPS] ?? [];
        if(is_string($groups)) $groups = [$groups];
        if( !($context[self::IGNORE] ?? false) && ! in_array(self::SERIALIZABLE, $groups)){
            $groups[] = self::SERIALIZABLE;
            $context[AN::GROUPS] = $groups;
        }
        return $this->normalizer?->normalize($object, $format, $context);
    }

    /**
     * @inheritdoc
     */
    public function supportsNormalization(mixed $data, string $format = null, array $context = []): bool
    {
        return $data instanceof MongoBase;
    }
}