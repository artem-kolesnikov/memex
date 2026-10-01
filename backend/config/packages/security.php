<?php

use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Yaml\Yaml;

// Symfony takes firewalls and access rules from one configuration section only, so
// the core's (config/security.yaml) and the edition's (config/edition/security.yaml)
// are joined here. The edition's firewalls and rules come first: the core's main
// firewall and its last rule match every path.
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $core = \dirname(__DIR__).'/security.yaml';
    $edition = \dirname(__DIR__).'/edition/security.yaml';
    $builder->addResource(new FileResource($core));
    $builder->addResource(new FileExistenceResource($edition));

    $security = Yaml::parseFile($core)['security'];
    if (is_file($edition)) {
        $builder->addResource(new FileResource($edition));
        $own = Yaml::parseFile($edition)['security'];
        $security['providers'] = [...$own['providers'] ?? [], ...$security['providers']];
        $security['firewalls'] = [...$own['firewalls'] ?? [], ...$security['firewalls']];
        $security['access_control'] = [...$own['access_control'] ?? [], ...$security['access_control']];
    }

    $container->extension('security', $security);
};
