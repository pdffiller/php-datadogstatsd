<?php

require '../vendor/autoload.php';

use DataDog\BatchedDogStatsd;
use DataDog\DogStatsd;

$statsd = new DogStatsd();
$statsd->increment('web.page_views');
$statsd->histogram('web.render_time', 15);
$statsd->distribution('web.render_time', 15);
$statsd->set('web.uniques', 3 /* a unique user id */);
$statsd->serviceCheck('my.service.check', DogStatsd::CRITICAL);
$statsd->event("Event title", ["text" => "Event text"]);

//All the following metrics will be sent in a single UDP packet to the statsd server
$batchedStatsd = new BatchedDogStatsd();
$batchedStatsd->increment('web.page_views');
$batchedStatsd->histogram('web.render_time', 15);
$batchedStatsd->set('web.uniques', 3 /* a unique user id */);
$batchedStatsd->flushBuffer(); // Necessary
