<?php

declare(strict_types=1);

namespace App\Storage;

use App\Service\EmbeddingModel;

/**
 * A vault's vectors, emptied and their tables made again for another model.
 * sqlite-vec fixes a table's dimension when it is created, so a model of
 * another size needs new tables, and no vector of the old model may survive in
 * them. The notes stay; the embedding sweep finds them all unembedded.
 */
final class VectorTables
{
    /** @return list<string> statements, to run in one transaction */
    public static function rebuild(EmbeddingModel $model): array
    {
        return [
            'DELETE FROM note_embedding_chunks',
            'DELETE FROM note_embeddings',
            'DELETE FROM note_neighbours',
            'DELETE FROM note_neighbours_stale',
            'DROP TABLE note_embedding_vectors',
            'DROP TABLE note_embedding_chunk_vectors',
            sprintf('CREATE VIRTUAL TABLE note_embedding_vectors USING vec0(embedding float[%d] distance_metric=cosine)', $model->dimensions()),
            sprintf('CREATE VIRTUAL TABLE note_embedding_chunk_vectors USING vec0(embedding float[%d] distance_metric=cosine)', $model->dimensions()),
            sprintf("UPDATE settings SET embedding_model = '%s'", $model->value),
        ];
    }
}
