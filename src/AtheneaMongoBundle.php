<?php

namespace Athenea\Mongo;

use Athenea\Mongo\DependencyInjection\AtheneaMongoExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class AtheneaMongoBundle extends AbstractBundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new AtheneaMongoExtension();
    }
}
