<?php

namespace Monolog\Handler;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;

/**
 * This is just an example.
 */
class MysqlHandler extends AbstractProcessingHandler
{
    /**
     * Writes the record down to the log of the implementing handler
     *
     * @param  $record []
     * @return void
     */
    protected function write(array $record): void
    {
        var_dump($record);
        return;
    }
}
