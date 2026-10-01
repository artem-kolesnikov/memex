<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * memex is one core and an edition around it. The edition's configuration sits in
 * config/edition/, laid out like config/ itself, and loads after the core's, so an
 * edition can replace any core service with its own.
 */
class Kernel extends BaseKernel
{
    use MicroKernelTrait {
        configureContainer as private configureCoreContainer;
        configureRoutes as private configureCoreRoutes;
    }

    private function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $this->configureCoreContainer($container, $loader, $builder);

        $configDir = $this->getConfigDir();
        $container->import($configDir.'/{edition}/{packages}/*.{php,yaml}');
        $container->import($configDir.'/{edition}/{packages}/'.$this->environment.'/*.{php,yaml}');
        $container->import($configDir.'/{edition}/{services}.yaml');
        $container->import($configDir.'/{edition}/{services}_'.$this->environment.'.yaml');
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $this->configureCoreRoutes($routes);

        $routes->import($this->getConfigDir().'/{edition}/{routes}/*.{php,yaml}');
    }
}
