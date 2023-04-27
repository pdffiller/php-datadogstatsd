<?php

namespace DataDog;

/**
 * Class BatchedDogStatsd
 *
 * Useful for sending batches of UDP messages to DataDog after reaching a
 * configurable max buffer size of unsent messages.
 *
 * Buffer defaults to 50 messages;
 *
 * @package DataDog
 */
class BatchedDogStatsd extends DogStatsd
{
    public static $maxBufferLength = 50;
    private static $buffer = [];
    private static $bufferLength = 0;

    public function __construct(array $config = [])
    {
        // by default the telemetry is enabled for BatchedDogStatsd
        if (!isset($config["disable_telemetry"])) {
            $config["disable_telemetry"] = false;
        }
        parent::__construct($config);
    }

    public function __destruct()
    {
        if (static::$bufferLength) {
            $this->flushBuffer();
        }

        parent::__destruct();
    }

    /**
     * @param string $message
     */
    public function report($message)
    {
        static::$buffer[] = $message;

        if (++static::$bufferLength > static::$maxBufferLength) {
            $this->flushBuffer();
        }
    }

    /**
     * @deprecated flush_buffer will be removed in future versions in favor of flushBuffer
     */
    public function flush_buffer() // phpcs:ignore
    {
        $this->flushBuffer();
    }


    public function flushBuffer()
    {
        $this->flush(implode("\n", static::$buffer));
        static::$buffer = [];
        static::$bufferLength = 0;
    }
}
