<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\EmbeddingModel;
use Psr\Container\ContainerInterface;

final class Embeddings
{
    /** The model every vault a test creates embeds with: the edition's first. */
    public static function model(ContainerInterface $container): EmbeddingModel
    {
        return EmbeddingModel::from($container->getParameter('memex.embedding_models')[0]);
    }
}
