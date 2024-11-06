<?php
namespace Athenea\Mongo\Subscriber;

use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Subscriber for MongoDB queries to log each query if the option is enabled.
 * @see https://www.php.net/manual/en/class.mongodb-driver-monitoring-commandsubscriber.php
 */
class MongoQuerySubscriber implements CommandSubscriber
{
    private const MAX_DEPTH = 20; // Limit the depth for nested arrays
    private const MAX_FIELD_SIZE = 1000; // Limit for large field content
    private const MAX_JSON_SIZE = 10000; // Limit for overall JSON string size

    /**
     * @param LoggerInterface $loggerInterface Symfony logger
     */
    public function __construct(private LoggerInterface $loggerInterface, private ?Stopwatch $stopWatch)
    {
    }

    /**
     * @inheritdoc
     */
    public function commandStarted(CommandStartedEvent $event): void
    {
        $requestId = $event->getRequestId();
        $commandName = $event->getCommandName();
        $eventId = "$commandName $requestId";
        $this->stopWatch?->start($eventId, 'athenea.mongo.query_subscriber');
        
        // Convert command object to array and apply filtering
        $command = json_decode(json_encode($event->getCommand()), true);
        if (($command['insert'] ?? null) === 'fs.chunks') {
            $command = ['insert' => 'fs.chunks'];
        }

        $context = [
            'operationId' => $event->getOperationId(),
            'requestId' => $event->getRequestId(),
            'database' => $event->getDatabaseName(),
            'server' => $event->getServer()
        ];

        // Apply filter and add filtered command to the context
        if ($command) {
            $filteredCommand = $this->filterJson($command);
            $context['command'] = $filteredCommand;
            $jsonString = json_encode($filteredCommand);

            // Enforce maximum size on the final JSON string
            if (strlen($jsonString) > self::MAX_JSON_SIZE) {
                $jsonString = substr($jsonString, 0, self::MAX_JSON_SIZE) . '... [truncated due to size limit]';
                unset($context['command']);
            }
            $context['json'] = $jsonString;
        }

        $this->loggerInterface->debug(
            "MONGODB: command started " . $event->getCommandName() . " " . $event->getRequestId(),
            $context
        );
    }

    /**
     * @inheritdoc
     */
    public function commandSucceeded(CommandSucceededEvent $event): void
    {
        $requestId = $event->getRequestId();
        $commandName = $event->getCommandName();
        $eventId = "$commandName $requestId";
        $this->stopWatch?->stop($eventId, 'athenea.mongo.query_subscriber');

        $this->loggerInterface->debug(
            "MONGODB: command succeeded " . $event->getCommandName() . " " . $event->getRequestId(),
            [
                'operationId' => $event->getOperationId(),
                'requestId' => $event->getRequestId(),
                'durationMicros' => $event->getDurationMicros(),
                'server' => $event->getServer()
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public function commandFailed(CommandFailedEvent $event): void
    {
        $requestId = $event->getRequestId();
        $commandName = $event->getCommandName();
        $eventId = "$commandName $requestId";
        $this->stopWatch?->stop($eventId, 'athenea.mongo.query_subscriber');

        $this->loggerInterface->debug(
            "MONGODB: command failed " . $event->getCommandName() . " " . $event->getRequestId(),
            [
                'operationId' => $event->getOperationId(),
                'requestId' => $event->getRequestId(),
                'durationMicros' => $event->getDurationMicros(),
                'server' => $event->getServer(),
                'error' => $event->getError()
            ]
        );
    }

    /**
     * Filters and truncates large fields in the JSON data to prevent memory overload.
     *
     * @param array $json The JSON data to filter
     * @param int $depth The current recursion depth
     * @return array The filtered JSON data
     */
    private function filterJson(array $json, int $depth = 1): array
    {
        $newJson = [];
        foreach ($json as $key => $value) {
            // Limit recursion depth
            if ($depth > self::MAX_DEPTH) {
                $newJson[$key] = '...';
                continue;
            }

            // Mask sensitive information
            if ($key === 'base64') {
                $newJson[$key] = strlen($value) > self::MAX_FIELD_SIZE 
                    ? substr($value, 0, self::MAX_FIELD_SIZE) . '... [truncated]' 
                    : $value;
            } elseif (str_contains($key, 'password') || str_contains($key, 'token')) {
                $newJson[$key] = '********';
            } elseif (is_array($value)) {
                // Recursively filter nested arrays
                $newJson[$key] = $this->filterJson($value, $depth + 1);
            } else {
                // Truncate large fields to MAX_FIELD_SIZE
                $newJson[$key] = is_string($value) && strlen($value) > self::MAX_FIELD_SIZE
                    ? substr($value, 0, self::MAX_FIELD_SIZE) . '... [truncated]'
                    : $value;
            }
        }
        return $newJson;
    }
}