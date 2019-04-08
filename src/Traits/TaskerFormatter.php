<?php

namespace Wilr\SilverStripe\Tasker\Traits;

use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataObject;
use Exception;

trait TaskerFormatter
{
    public $quiet = false;

    public $verbose = false;

    /**
     * @param string $message
     */
    protected function echoWarning($message)
    {
        if ($this->quiet) {
            return;
        }

        echo PHP_EOL. "\033[31m [WARNING] ". $message ."\033[0m". PHP_EOL;
    }

    /**
     * @param string $heading
     */
    protected function echoHeading($heading, $step = '.', $total = '.')
    {
        $this->progressCount = 0;

        if ($this->quiet) {
            return;
        }

        echo PHP_EOL.'+-----------------------------------------------'.PHP_EOL;
        echo "\033[32m| Step [$step/$total]:\033[0m \033[34m". $heading ."\033[0m";
        echo PHP_EOL.'+-----------------------------------------------'.PHP_EOL;
    }

    /**
     * @param string $text
     */
    protected function echoSuccess($text)
    {
        if ($this->quiet) {
            return;
        }

        echo "\033[37m". $text ."\033[0m" .PHP_EOL.PHP_EOL;
    }

    protected function echoMessage($text)
    {
        if ($this->quiet) {
            return;
        }

        $this->progressCount = 0;

        echo PHP_EOL. " [*] \033[31m". $text ."\033[0m" .PHP_EOL;
    }

    protected function echoLine()
    {
        echo PHP_EOL;
    }

    protected function echoTick($message = '')
    {
        $this->progressCount = 0;

        if ($this->quiet) {
            return;
        }

        echo "\xE2\x9C\x85 ". $message .PHP_EOL;
    }

    protected function echoProgress()
    {
        if ($this->quiet) {
            return;
        }

        if ($this->progressCount > 80) {
            $this->progressCount = 0;

            echo PHP_EOL;
        }

        $this->progressCount++;

        echo '.';
    }
}
