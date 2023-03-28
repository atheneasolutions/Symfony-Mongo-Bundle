<?php

namespace Athenea\Mongo\DependencyInjection;

use Athenea\Mongo\Service\MongoService;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class AtheneaMongoExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.yaml');
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $definition = $container->getDefinition('athenea.mongo.mongo_service');
        $mongo = $config['mongodb'];
        $definition->replaceArgument('$log', $mongo['log']);
        $definition->replaceArgument('$url', $mongo['url']);
        $definition->replaceArgument('$defaultDb', $mongo['default_db']);
    }
    
}