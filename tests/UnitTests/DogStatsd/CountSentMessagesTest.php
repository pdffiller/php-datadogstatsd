<?php

namespace DataDog\UnitTests\DogStatsd;

use DataDog\DogStatsd;
use DataDog\TestHelpers\SocketSpyTestCase;

class CountSentMessagesTest extends SocketSpyTestCase
{
    public function testReportDoesNotSendIfBufferNotFilled()
    {
        $batchedDog = new DogStatsd();

        $batchedDog->report('some fake UDP message');

        $spy = $this->getSocketSpy();

        $this->assertSame(
            1,
            count($spy->argsFromSocketSendtoCalls),
            'Should sent UDP message'
        );

        $this->assertSame(
            0,
            count($spy->argsFromSocketCloseCalls),
            'Socket should be not closed'
        );
    }

    public function testReportSendsOnceBufferIsFilled()
    {
        $batchedDog = new DogStatsd();

        $batchedDog::$maxCountMessagesOnSocketSession = 2;

        $udpMessage = 'some fake UDP message';

        $spy = $this->getSocketSpy();

        $batchedDog->gauge($udpMessage . '1', 21);
        $batchedDog->gauge($udpMessage . '2', 21);
        $batchedDog->gauge($udpMessage . '3', 21);

        $this->assertSame(
            3,
            count($spy->argsFromSocketSendtoCalls),
            'Should send all UDP messages'
        );
        $this->assertSame(
            1,
            count($spy->argsFromSocketCloseCalls),
            'Socket was not closed'
        );

        $this->assertSame(
            "some fake UDP message1:21|g\n",
            $spy->argsFromSocketSendtoCalls[0][1],
            'Should concatenate UDP messages with newlines'
        );
        $this->assertSame(
            "some fake UDP message2:21|g\n",
            $spy->argsFromSocketSendtoCalls[1][1],
            'Should concatenate UDP messages with newlines'
        );
        $this->assertSame(
            "some fake UDP message3:21|g\n",
            $spy->argsFromSocketSendtoCalls[2][1],
            'Should concatenate UDP messages with newlines'
        );
    }
}
