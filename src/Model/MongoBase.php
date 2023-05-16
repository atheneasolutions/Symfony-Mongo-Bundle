<?php

namespace Athenea\Mongo\Model;

use Athenea\MongoLib\Attribute\BsonSerialize;
use Athenea\MongoLib\Model\Base;
use DateTime;
use MongoDB\BSON\ObjectId;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * Classe que representa un document de Mongo base amb camps comuns a tots els documents que fem a EMC
 * 
 * Concretament conté:
 * * mongo Id
 * * data de creació
 * * data d'actualització
 */
abstract class MongoBase extends Base {

    /**
     * Identificador mongo únic
     */
    #[BsonSerialize(name: "_id")]    
    protected ?ObjectId $id;

    /**
     * Data de creació
     */
    #[BsonSerialize]
    protected DateTime $createdAt;

    /**
     * Data d'actualització
     */
    #[BsonSerialize]
    protected DateTime $updatedAt;


    public function __construct()
    {
        $this->createdAt = new DateTime();
        $this->updatedAt = new DateTime();
    }

    /**
     * Obtenir l'dentificador mongo únic
     * @return ?ObjectId Identificador mongo únic
     */
    
    public function getId(): ?ObjectId
    {
        return $this->id;
    }


    /**
     * Set l'dentificador mongo únic
     * @param ?ObjectId Identificador mongo únic
     * @return self
     */
    public function setId(?ObjectId $id): ?self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Obté la data de creació del registre
     * @return DateTime data de creació del registre
     */
    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    /**
     * Introdueix la data de creació del registre
     * @param DateTime data de creació del registre
     * @return self
     */
    public function setCreatedAt(DateTime $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /**
     * Obté la data d'actualització del registre
     * @return DateTime data d'actualització del registre
     */
    public function getUpdatedAt(): DateTime
    {
        return $this->updatedAt;
    }

    /**
     * Introdueix la data d'actualització del registre
     * @param DateTime data d'actualització del registre
     * @return self
     */
    public function setUpdatedAt(DateTime $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }


    /**
     * Obté el nom de la col·lecció de mongo
     * @return string nom de la col·lecció de mongo
     */
    abstract public static function collectionName(): string;

}