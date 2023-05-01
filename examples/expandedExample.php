<?php

require '../vendor/autoload.php';

use DataDog\DogStatsd;

$statsd = new DogStatsd();

$runFor = 5; // Set to five minutes. Increase or decrease to have script run longer or shorter.
$scriptStartTime = time();

echo "Script starting.\n";

// Send metrics and events for 5 minutes.
while (time() < $scriptStartTime + ($runFor * 60)) {
    $startTime1 = microtime(true);
    $statsd->increment('web.page_views');
    $statsd->histogram('web.render_time', 15);
    $statsd->distribution('web.render_time', 15);
    $statsd->set('web.uniques', 3); // A unique user id

    runFunction($statsd);
    $statsd->timing('test.data.point', microtime(true) - $startTime1, 1, ['tagname' => 'php_example_tag_1']);

    sleep(1); // Sleep for one second
}

echo "Script has completed.\n";

/**
 * @throws Exception
 */
function runFunction($statsd)
{
    $startTime = microtime(true);

    $testArray = [];
    for ($i = 0; $i < random_int(1, 1000000000); $i++) {
        $testArray[$i] = $i;

        // Simulate an event at every 1000000th element
        if ($i % 1000000 == 0) {
            echo "Event simulated.\n";
            $statsd->event('A thing broke!', [
                'alert_type' => 'error',
                'aggregation_key' => 'test_aggr',
            ]);
            $statsd->event('Now it is fixed.', [
                'alert_type' => 'success',
                'aggregation_key' => 'test_aggr',
            ]);
        }
    }
    unset($testArray);
    $statsd->timing('test.data.point', microtime(true) - $startTime, 1, ['tagname' => 'php_example_tag_2']);
}
