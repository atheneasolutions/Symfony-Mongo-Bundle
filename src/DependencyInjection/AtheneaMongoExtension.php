<?php

namespace Athenea\Mongo\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class AtheneaMongoExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
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