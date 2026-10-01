<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Refusal to take one of memex's own tags out of a vocabulary.
 *
 * Carries the tag's name and {@see SystemTags}' reason for holding it, because
 * this is the one refusal in the app that is aimed straight at the person who
 * clicked: the answer they need is not "forbidden" but what would have stopped
 * working. The controller renders both.
 */
class SystemTagException extends \RuntimeException
{
    public function __construct(public readonly string $tag, public readonly string $reason)
    {
        parent::__construct('“'.$tag.'” is a tag memex reads. '.$reason);
    }
}
