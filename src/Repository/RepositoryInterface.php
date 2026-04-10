<?php

namespace Athenea\Mongo\Repository;

use Athenea\Mongo\Model\MongoBase;
use MongoDB\BSON\ObjectId;
use MongoDB\InsertManyResult;
use MongoDB\InsertOneResult;
use MongoDB\UpdateResult;

interface RepositoryInterface
{
    public function findById(string $id): ?MongoBase;
    public function findByObjectId(ObjectId $id): ?MongoBase;
    public function find(array $filter = [], array $options = []);
    public function findOne(array $filter = [], array $options = []): ?MongoBase;

    public function insertOne(?MongoBase $doc = null, array $options = []): InsertOneResult;
    public function insertMany(array $docs = null, array $options = []): InsertManyResult;

    public function updateOne(array $filter = [], array $update = [], array $options = []): UpdateResult;
    public function updateMany(array $filter = [], array $update = [], array $options = []): UpdateResult;
    public function updateDoc(MongoBase $doc, array $update = [], array $options = []): UpdateResult;
    public function updateWithChangeArray(MongoBase $doc, array $changeArray = [], array $options = []): UpdateResult;

    public function findAndReplace(array $filter = [], ?MongoBase $doc = null, array $options = []): ?MongoBase;
    public function replace(?MongoBase $doc = null, array $options = []): ?MongoBase;

    public function reHydrate(MongoBase $doc, bool $bson = false): void;
    public function deleteDoc(MongoBase $doc, array $options = []);
}
