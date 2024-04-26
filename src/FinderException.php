<?php

namespace neilanderson\ServerFinder;

class FinderException extends \Exception
{
    public function __construct($message)
    {
        parent::__construct($message);

        $this->message = "Finder error: " . $message;
    }
}