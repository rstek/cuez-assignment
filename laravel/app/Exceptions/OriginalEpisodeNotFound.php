<?php

namespace App\Exceptions;

class OriginalEpisodeNotFound extends DuplicationException
{
    public function __construct(public readonly int $episodeId)
    {
        parent::__construct("Original episode not found: id={$episodeId}");
    }
}

