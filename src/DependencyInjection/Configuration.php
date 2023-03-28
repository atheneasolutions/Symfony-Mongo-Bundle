<?php
namespace Athenea\Mongo\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('athenea_mongo');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('mongodb')->isRequired()
                    ->children()
                        ->booleanNode('log')->defaultFalse()->end()
                        ->scalarNode('url')->isRequired()->end()
                        ->scalarNode('default_db')->isRequired()->end()
                    ->end()
                ->end() // twitter
            ->end()
        ;

        return $treeBuilder;
    }
}