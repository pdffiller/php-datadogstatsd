<?php

namespace DataDog;

/**
 * Datadog implementation of StatsD
 **/
class DogStatsd
{
    // phpcs:disable
    const OK = 0;
    const WARNING = 1;
    const CRITICAL = 2;
    const UNKNOWN = 3;

    const DEFAULT_MAX_ATTEMPTS_TO_SEND = 1;
    // phpcs:enable
    public static $version = '1.5.6';
    private static $eventUrl = '/api/v1/events';
    /**
     * @var bool|resource|\Socket
     */
    protected $socket = false;
    /**
     * @var int
     */
    protected $maxAttemptsToSend = self::DEFAULT_MAX_ATTEMPTS_TO_SEND;
    protected $retryStatusCodes = [
        10053 => true,
        10054 => true,
        10058 => true,
        10060 => true,
        10061 => true,
        100 => true,
        101 => true,
        102 => true,
        103 => true,
        104 => true,
        105 => true,
        107 => true,
        108 => true,
        110 => true,
        111 => true,
        112 => true,
        121 => true,
        125 => true,
    ];
    /**
     * @var string
     */
    private $host;
    /**
     * @var int
     */
    private $port;

    // Telemetry
    /**
     * @var string
     */
    private $socketPath;
    /**
     * @var string
     */
    private $datadogHost;
    /**
     * @var array Tags to apply to all metrics
     */
    private $globalTags;
    /**
     * @var int Number of decimals to use when formatting numbers to strings
     */
    private $decimalPrecision;
    /**
     * @var string The prefix to apply to all metrics
     */
    private $metricPrefix;
    private $disable_telemetry;
    private $telemetry_tags;
    private $metrics_sent;
    private $events_sent;
    private $service_checks_sent;
    private $bytes_sent;
    private $bytes_dropped;
    private $packets_sent;

    // Used for the telemetry tags
    private $packets_dropped;

    /**
     * DogStatsd constructor, takes a configuration array. The configuration can take any of the following values:
     * host,
     * port,
     * datadog_host,
     * global_tags,
     * decimal_precision,
     * metric_prefix
     *
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $agentHost = getenv('DD_AGENT_HOST') ?: 'localhost';
        $this->host = isset($config['host']) ? $config['host'] : $agentHost;

        $dogStatsdPort = getenv('DD_DOGSTATSD_PORT') ?: 8125;
        $this->port = (int) (isset($config['port']) ? $config['port'] : $dogStatsdPort);

        $this->socketPath = isset($config['socket_path']) ? $config['socket_path'] : null;

        $this->maxAttemptsToSend = isset($config['max_attempts_to_send'])
            ? $config['max_attempts_to_send']
            : self::DEFAULT_MAX_ATTEMPTS_TO_SEND;

        $this->datadogHost = isset($config['datadog_host']) ? $config['datadog_host'] : 'https://app.datadoghq.com';

        $this->decimalPrecision = isset($config['decimal_precision']) ? $config['decimal_precision'] : 2;

        $this->globalTags = isset($config['global_tags']) ? $config['global_tags'] : [];
        if (getenv('DD_ENTITY_ID')) {
            $this->globalTags['dd.internal.entity_id'] = getenv('DD_ENTITY_ID');
        }

        $this->metricPrefix = isset($config['metric_prefix']) ? "$config[metric_prefix]." : '';

        // by default the telemetry is disable
        $this->disable_telemetry = isset($config["disable_telemetry"]) ? $config["disable_telemetry"] : true;
        $transport_type = !is_null($this->socketPath) ? "uds" : "udp";
        $this->telemetry_tags = $this->serializeTags(
            [
                "client" => "php",
                "client_version" => self::$version,
                "client_transport" => $transport_type,
            ]
        );

        $this->resetTelemetry();
    }

    public function __destruct()
    {
        $this->closeSocket();
    }

    /**
     * Log timing information
     *
     * @param string $stat       The metric to in log timing info for.
     * @param float $time        The elapsed time (ms) to log
     * @param float $sampleRate  the rate (0-1) for sampling.
     * @param array|string $tags Key Value array of Tag => Value, or single tag as string
     *
     * @return void
     */
    public function timing($stat, $time, $sampleRate = 1.0, $tags = null)
    {
        $normalizedValue = $this->normalizeValue($time);
        $this->send([$stat => "$normalizedValue|ms"], $sampleRate, $tags);
    }

    /**
     * A convenient alias for the timing function when used with micro-timing
     *
     * @param string $stat       The metric name
     * @param float $time        The elapsed time to log, IN SECONDS
     * @param float $sampleRate  the rate (0-1) for sampling.
     * @param array|string $tags Key Value array of Tag => Value, or single tag as string
     *
     * @return void
     **/
    public function microtiming($stat, $time, $sampleRate = 1.0, $tags = null)
    {
        $this->timing($stat, $time * 1000, $sampleRate, $tags);
    }

    /**
     * Gauge
     *
     * @param string $stat       The metric
     * @param float $value       The value
     * @param float $sampleRate  the rate (0-1) for sampling.
     * @param array|string $tags Key Value array of Tag => Value, or single tag as string
     *
     * @return void
     **/
    public function gauge($stat, $value, $sampleRate = 1.0, $tags = null)
    {
        $normalizedValue = $this->normalizeValue($value);
        $this->send([$stat => "$normalizedValue|g"], $sampleRate, $tags);
    }

    /**
     * Histogram
     *
     * @param string $stat       The metric
     * @param float $value       The value
     * @param float $sampleRate  the rate (0-1) for sampling.
     * @param array|string $tags Key Value array of Tag => Value, or single tag as string
     *
     * @return void
     **/
    public function histogram($stat, $value, $sampleRate = 1.0, $tags = null)
    {
        $normalizedValue = $this->normalizeValue($value);
        $this->send([$stat => "$normalizedValue|h"], $sampleRate, $tags);
    }

    /**
     * Distribution
     *
     * @param string $stat       The metric
     * @param float $value       The value
     * @param float $sampleRate  the rate (0-1) for sampling.
     * @param array|string $tags Key Value array of Tag => Value, or single tag as string
     *
     * @return void
     **/
    public function distribution($stat, $value, $sampleRate = 1.0, $tags = null)
    {
        $normalizedValue = $this->normalizeValue($value);
        $this->send([$stat => "$normalizedValue|d"], $sampleRate, $tags);
    }

    /**
     * Set
     *
     * @param string $stat        The metric
     * @param string|float $value The value
     * @param float $sampleRate   the rate (0-1) for sampling.
     * @param array|string $tags  Key Value array of Tag => Value, or single tag as string
     *
     * @return void
     **/
    public function set($stat, $value, $sampleRate = 1.0, $tags = null)
    {
        if (!is_string($value)) {
            $normalizedValue = $this->normalizeValue($value);
        } else {
            $normalizedValue = $value;
        }

        $this->send([$stat => "$normalizedValue|s"], $sampleRate, $tags);
    }

    /**
     * Increments one or more stats counters
     *
     * @param string|array $stats The metric(s) to increment.
     * @param float $sampleRate   the rate (0-1) for sampling.
     * @param array|string $tags  Key Value array of Tag => Value, or single tag as string
     * @param int $value          the amount to increment by (default 1)
     *
     * @return void
     **/
    public function increment($stats, $sampleRate = 1.0, $tags = null, $value = 1)
    {
        $this->updateStats($stats, $value, $sampleRate, $tags);
    }

    /**
     * Decrements one or more stats counters.
     *
     * @param string|array $stats The metric(s) to decrement.
     * @param float $sampleRate   the rate (0-1) for sampling.
     * @param array|string $tags  Key Value array of Tag => Value, or single tag as string
     * @param int $value          the amount to decrement by (default -1)
     *
     * @return void
     **/
    public function decrement($stats, $sampleRate = 1.0, $tags = null, $value = -1)
    {
        if ($value > 0) {
            $value = -$value;
        }
        $this->updateStats($stats, $value, $sampleRate, $tags);
    }

    /**
     * Updates one or more stats counters by arbitrary amounts.
     *
     * @param string|array $stats The metric(s) to update. Should be either a string or array of metrics.
     * @param int $delta          The amount to increment/decrement each metric by.
     * @param float $sampleRate   the rate (0-1) for sampling.
     * @param array|string $tags  Key Value array of Tag => Value, or single tag as string
     *
     * @return void
     **/
    public function updateStats($stats, $delta = 1, $sampleRate = 1.0, $tags = null)
    {
        $normalizedValue = $this->normalizeValue($delta);
        if (!is_array($stats)) {
            $stats = [$stats];
        }
        $data = [];
        foreach ($stats as $stat) {
            $data[$stat] = "$normalizedValue|c";
        }
        $this->send($data, $sampleRate, $tags);
    }

    /**
     * Squirt the metrics over UDP
     *
     * @param array $data        Incoming Data
     * @param float $sampleRate  the rate (0-1) for sampling.
     * @param array|string $tags Key Value array of Tag => Value, or single tag as string
     *
     * @return void
     **/
    public function send($data, $sampleRate = 1.0, $tags = null)
    {
        $normalizedValue = $this->normalizeValue($sampleRate);
        $this->metrics_sent += count($data);
        // sampling
        $sampledData = [];
        if ($normalizedValue < 1) {
            foreach ($data as $stat => $value) {
                if ((mt_rand() / mt_getrandmax()) <= $normalizedValue) {
                    $sampledData[$stat] = "$value|@$normalizedValue";
                }
            }
        } else {
            $sampledData = $data;
        }

        if (empty($sampledData)) {
            return;
        }

        foreach ($sampledData as $stat => $value) {
            $value .= $this->serializeTags($tags);
            $this->report("{$this->metricPrefix}$stat:$value");
        }
    }

    /**
     * @param string $name       service check name
     * @param int $status        service check status code (see OK, WARNING,...)
     * @param array|string $tags Key Value array of Tag => Value, or single tag as string
     * @param string $hostname   hostname to associate with this service check status
     * @param string $message    message to associate with this service check status
     * @param int $timestamp     timestamp for the service check status (defaults to now)
     *
     * @return     void
     **@deprecated service_check will be removed in future versions in favor of serviceCheck
     *
     * Send a custom service check status over UDP
     */
    public function service_check( // phpcs:ignore
        $name,
        $status,
        $tags = null,
        $hostname = null,
        $message = null,
        $timestamp = null
    ) {
        $this->serviceCheck($name, $status, $tags, $hostname, $message, $timestamp);
    }

    /**
     * Send a custom service check status over UDP
     *
     * @param string $name       service check name
     * @param int $status        service check status code (see OK, WARNING,...)
     * @param array|string $tags Key Value array of Tag => Value, or single tag as string
     * @param string $hostname   hostname to associate with this service check status
     * @param string $message    message to associate with this service check status
     * @param int $timestamp     timestamp for the service check status (defaults to now)
     *
     * @return void
     **/
    public function serviceCheck(
        $name,
        $status,
        $tags = null,
        $hostname = null,
        $message = null,
        $timestamp = null
    ) {
        $msg = "_sc|$name|$status";

        if ($timestamp !== null) {
            $msg .= sprintf("|d:%s", $timestamp);
        }
        if ($hostname !== null) {
            $msg .= sprintf("|h:%s", $hostname);
        }
        $msg .= $this->serializeTags($tags);
        if ($message !== null) {
            $msg .= sprintf('|m:%s', $this->escapeScMessage($message));
        }

        ++$this->service_checks_sent;
        $this->report($msg);
    }

    public function report($message)
    {
        $this->flush($message);
    }

    public function flush($message)
    {
        $message .= $this->flushTelemetry() . PHP_EOL;

        $res = $this->sendMessage($message);

        if (!empty($res)) {
            $this->resetTelemetry();
            $this->bytes_sent += strlen($message);
            ++$this->packets_sent;
        } else {
            $this->bytes_dropped += strlen($message);
            ++$this->packets_dropped;
        }
    }

    /**
     * Formats $vals array into event for submission to Datadog via UDP
     *
     * @param array $vals  Optional values of the event. See
     *                     https://docs.datadoghq.com/api/?lang=bash#post-an-event for the valid keys
     *
     * @return bool
     */
    public function event($title, $vals = [])
    {
        // Format required values title and text
        $text = isset($vals['text']) ? (string) $vals['text'] : '';

        // Format fields into string that follows Datadog event submission via UDP standards
        //   http://docs.datadoghq.com/guides/dogstatsd/#events
        $fields = '';
        $fields .= ($title);
        $textField = ($text) ? '|' . str_replace("\n", "\\n", $text) : '|';
        $fields .= $textField;
        $fields .= (isset($vals['date_happened'])) ? '|d:' . ((string) $vals['date_happened']) : '';
        $fields .= (isset($vals['hostname'])) ? '|h:' . ((string) $vals['hostname']) : '';
        $fields .= (isset($vals['aggregation_key'])) ? '|k:' . ((string) $vals['aggregation_key']) : '';
        $fields .= (isset($vals['priority'])) ? '|p:' . ((string) $vals['priority']) : '';
        $fields .= (isset($vals['source_type_name'])) ? '|s:' . ((string) $vals['source_type_name']) : '';
        $fields .= (isset($vals['alert_type'])) ? '|t:' . ((string) $vals['alert_type']) : '';
        $fields .= (isset($vals['tags'])) ? $this->serializeTags($vals['tags']) : '';

        $title_length = strlen($title);
        $text_length = strlen($textField) - 1;

        ++$this->events_sent;
        $this->report('_e{' . $title_length . ',' . $text_length . '}:' . $fields);

        return true;
    }

    /**
     * @return bool|resource|\Socket
     */
    protected function getSocket()
    {
        if ($this->socket) {
            return $this->socket;
        }

        $domain = is_null($this->socketPath) ? AF_INET : AF_UNIX;
        $protocol = is_null($this->socketPath) ? SOL_UDP : 0;

        // Non - Blocking UDP I/O - Use IP Addresses!
        if ($this->socket = @socket_create($domain, SOCK_DGRAM, $protocol)) {
            socket_set_nonblock($this->socket);
        }

        return $this->socket;
    }

    protected function closeSocket()
    {
        if (!$this->socket) {
            return;
        }

        @socket_close($this->socket);

        $this->socket = false;
    }

    /**
     * @param string $message
     * @param int $attempt
     *
     * @return false|int|mixed
     */
    protected function sendMessage($message, $attempt = 0)
    {
        $res = false;

        if ($socket = $this->getSocket()) {
            if (!is_null($this->socketPath)) {
                $res = socket_sendto($socket, $message, strlen($message), 0, $this->socketPath);
            } else {
                $res = socket_sendto($socket, $message, strlen($message), 0, $this->host, $this->port);
            }
        }

        if ($res === false) {
            $socketError = $socket ? socket_last_error($socket) : socket_last_error();
            if (isset($this->retryStatusCodes[$socketError])) {
                $this->closeSocket();
                if ($attempt < $this->maxAttemptsToSend) {
                    return $this->sendMessage($message, ++$attempt);
                }
            }
        }

        return $res;
    }

    /**
     * Reset the telemetry value to zero
     */
    private function resetTelemetry()
    {
        $this->metrics_sent = 0;
        $this->events_sent = 0;
        $this->service_checks_sent = 0;
        $this->bytes_sent = 0;
        $this->bytes_dropped = 0;
        $this->packets_sent = 0;
        $this->packets_dropped = 0;
    }

    /**
     * Reset the telemetry value to zero
     */
    private function flushTelemetry()
    {
        if ($this->disable_telemetry) {
            return "";
        }

        return "\ndatadog.dogstatsd.client.metrics:{$this->metrics_sent}|c{$this->telemetry_tags}"
            . "\ndatadog.dogstatsd.client.events:{$this->events_sent}|c{$this->telemetry_tags}"
            . "\ndatadog.dogstatsd.client.service_checks:{$this->service_checks_sent}|c{$this->telemetry_tags}"
            . "\ndatadog.dogstatsd.client.bytes_sent:{$this->bytes_sent}|c{$this->telemetry_tags}"
            . "\ndatadog.dogstatsd.client.bytes_dropped:{$this->bytes_dropped}|c{$this->telemetry_tags}"
            . "\ndatadog.dogstatsd.client.packets_sent:{$this->packets_sent}|c{$this->telemetry_tags}"
            . "\ndatadog.dogstatsd.client.packets_dropped:{$this->packets_dropped}|c{$this->telemetry_tags}";
    }

    /**
     * Serialize tags to StatsD protocol
     *
     * @param string|array $tags The tags to be serialize
     *
     * @return string
     **/
    private function serializeTags($tags)
    {
        $all_tags = array_merge(
            $this->normalizeTags($this->globalTags),
            $this->normalizeTags($tags)
        );

        if (count($all_tags) === 0) {
            return '';
        }
        $tag_strings = [];
        foreach ($all_tags as $tag => $value) {
            if ($value === null) {
                $tag_strings[] = $tag;
            } elseif (is_bool($value)) {
                $tag_strings[] = $tag . ':' . ($value === true ? 'true' : 'false');
            } else {
                $tag_strings[] = $tag . ':' . $value;
            }
        }

        return '|#' . implode(',', $tag_strings);
    }

    /**
     * Turns tags in any format into an array of tags
     *
     * @param mixed $tags The tags to normalize
     *
     * @return array
     */
    private function normalizeTags($tags)
    {
        if ($tags === null) {
            return [];
        }

        if (is_array($tags)) {
            $data = [];
            foreach ($tags as $tag_key => $tag_val) {
                if (isset($tag_val)) {
                    $data[$tag_key] = $tag_val;
                } else {
                    $data[$tag_key] = null;
                }
            }

            return $data;
        }

        $tags = explode(',', $tags);
        $data = [];
        foreach ($tags as $tag_string) {
            if (false === strpos($tag_string, ':')) {
                $data[$tag_string] = null;
            } else {
                [$key, $value] = explode(':', $tag_string, 2);
                $data[$key] = $value;
            }
        }

        return $data;
    }

    private function escapeScMessage($msg)
    {
        return str_replace(["\n", "m:"], ["\\n", "m\:"], $msg);
    }

    /**
     * Normalize the value witout locale consideration before queuing the metric for sending
     *
     * @param float $value The value to normalize
     *
     * @return string Formatted value
     */
    private function normalizeValue($value)
    {
        // Controlls the way things are converted to a string.
        // Otherwise localization settings impact float to string conversion (e.x 1.3 -> 1,3 and 10000 => 10,000)

        return rtrim(rtrim(number_format((float) $value, $this->decimalPrecision, '.', ''), "0"), ".");
    }
}
