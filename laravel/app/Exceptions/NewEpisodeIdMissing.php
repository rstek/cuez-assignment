<?php

namespace App\Exceptions;

class NewEpisodeIdMissing extends DuplicationException
{
    public function __construct(public readonly int $duplicationId)
    {
        parent::__construct("New episode id not set on duplication: duplication_id={$duplicationId}");
    }
}

