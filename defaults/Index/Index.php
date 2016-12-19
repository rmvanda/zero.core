<?php


class Index extends Response{

    // Having this construction function prevents the 'index' from firing twice
    public function __construct(){ parent::__construct(); }

}
