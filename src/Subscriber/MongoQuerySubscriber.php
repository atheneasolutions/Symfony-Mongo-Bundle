<?php
namespace Athenea\Mongo\Subscriber;

use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Subscriber de les queries de mongo per poder fer logs de cada query si l'opció està activada.
 * @see App\Service\MongoService
 * @see https://www.php.net/manual/en/class.mongodb-driver-monitoring-commandsubscriber.php
 */
class MongoQuerySubscriber implements CommandSubscriber
{


    /**
     * @param LoggerInterface $loggerInterface Logger de symfony
     */
    public function __construct(private LoggerInterface $loggerInterface, private ?Stopwatch $stopWatch)
    {
    }

    /**
     * @inheritdoc
     */
    public function commandStarted( CommandStartedEvent $event ): void
    {
        $requestId = $event->getRequestId();
        $commandName = $event->getCommandName();
        $eventId = "$commandName $requestId";
        $this->stopWatch?->start($eventId, 'athenea.mongo.query_subscriber');
        $command = json_decode(json_encode($event->getCommand()), true);
        if(($command['insert'] ?? null) === 'fs.chunks') $command = ['insert' => 'fs.chunks'];
        $context = [
            'operationId' => $event->getOperationId(),
            'requestId' => $event->getRequestId(),
            'database' => $event->getDatabaseName(),
            'server' => $event->getServer()
        ];
        if($command){
            $command = $this->filterJson($command);
            $context['command'] = $command;
            $context['json'] = json_encode($command);
        }
        $this->loggerInterface->debug("MONGODB: command started ". $event->getCommandName(). " " . $event->getRequestId(), $context);
    }

    /**
     * @inheritdoc
     */
    public function commandSucceeded( CommandSucceededEvent $event ): void
    {
        $requestId = $event->getRequestId();
        $commandName = $event->getCommandName();
        $eventId = "$commandName $requestId";
        $this->stopWatch?->stop($eventId, 'athenea.mongo.query_subscriber');
        $this->loggerInterface->debug("MONGODB: command succeeded ". $event->getCommandName(). " " . $event->getRequestId(), [
            'operationId' => $event->getOperationId(),
            'requestId' => $event->getRequestId(),
            'durationMicros' => $event->getDurationMicros(),
            'server' => $event->getServer()
        ]);
    }

    /**
     * @inheritdoc
     */
    public function commandFailed( CommandFailedEvent $event ): void
    {
        $requestId = $event->getRequestId();
        $commandName = $event->getCommandName();
        $eventId = "$commandName $requestId";
        $this->stopWatch?->stop($eventId, 'athenea.mongo.query_subscriber');
        $this->loggerInterface->debug("MONGODB: command failed ". $event->getCommandName(). " " . $event->getRequestId(), [
            'operationId' => $event->getOperationId(),
            'requestId' => $event->getRequestId(),
            'durationMicros' => $event->getDurationMicros(),
            'server' => $event->getServer(),
            'error' => $event->getError()
        ]);
    }

    private function filterJson(array $json){
        $newJson = [];
        foreach($json as $key => $value){
            if($key === 'base64') $newJson[$key] = "base64 file content";
            if(
                str_contains($key, 'password') ||
                str_contains($key, 'token')
            ) $newJson[$key] = "********";
            else if(is_array($value)) $newJson[$key] = $this->filterJson($value);
            else $newJson[$key] = $value;
        }
        return $newJson;
    }

}