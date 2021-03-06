<?php

namespace VladimirYuldashev\LaravelQueueRabbitMQ\Queue;

use Illuminate\Support\Facades\Config;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use RuntimeException;
use Illuminate\Queue\Queue;
use Illuminate\Support\Str;
use Interop\Amqp\AmqpConsumer;
use Interop\Amqp\AmqpQueue;
use Interop\Amqp\AmqpTopic;
use Psr\Log\LoggerInterface;
use Interop\Amqp\AmqpContext;
use Interop\Amqp\AmqpMessage;
use Interop\Amqp\Impl\AmqpBind;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Jobs\RabbitMQJob;
use Throwable;

class RabbitMQQueue extends Queue implements QueueContract
{
    protected $sleepOnError;

    protected $queueName;
    protected $queueOptions;
    protected $exchangeOptions;

    protected $declaredExchanges = [];
    protected $declaredQueues = [];

    /**
     * @var AmqpContext
     */
    protected $context;
    protected $correlationId;

    public function __construct(AmqpContext $context, array $config)
    {
        $this->context = $context;

        $this->queueName = $config['queue'] ?? $config['options']['queue']['name'];
        $this->queueOptions = $config['options']['queue'];
        $this->queueOptions['arguments'] = isset($this->queueOptions['arguments']) ?
            json_decode($this->queueOptions['arguments'], true) : [];

        $this->exchangeOptions = $config['options']['exchange'];
        $this->exchangeOptions['arguments'] = isset($this->exchangeOptions['arguments']) ?
            json_decode($this->exchangeOptions['arguments'], true) : [];

        $this->sleepOnError = $config['sleep_on_error'] ?? 5;
    }

    /** {@inheritdoc} */
    public function size($queueName = null): int
    {
        /** @var AmqpQueue $queue */
        [$queue] = $this->declareEverything($queueName);

        return $this->context->declareQueue($queue);
    }

    /** {@inheritdoc} */
    public function push($job, $data = '', $queue = null): ?string
    {
        return $this->pushRaw($this->createPayload($job, $queue, $data), $queue, []);
    }

    /** {@inheritdoc} */
    public function pushRaw($payload, $queueName = null, array $options = []): ?string
    {
        try {
            /**
             * @var AmqpTopic
             * @var AmqpQueue $queue
             */
            [$queue, $topic] = $this->declareEverything($queueName);

            /** @var AmqpMessage $message */
            $message = $this->context->createMessage($payload);

            $message->setCorrelationId($this->getCorrelationId());
            $message->setContentType('application/json');
            $message->setDeliveryMode(AmqpMessage::DELIVERY_MODE_PERSISTENT);

            if (isset($options['content_encoding'])) {
                $message->setContentEncoding($options['content_encoding']);
            }

            if (isset($options['routing_key'])) {
                $message->setRoutingKey($options['routing_key']);
            } else {
                $message->setRoutingKey($queue->getQueueName());
            }

            if (isset($options['priority'])) {
                $message->setPriority($options['priority']);
            }

            if (isset($options['expiration'])) {
                $message->setExpiration($options['expiration']);
            }

            if (isset($options['delivery_tag'])) {
                $message->setDeliveryTag($options['delivery_tag']);
            }

            if (isset($options['consumer_tag'])) {
                $message->setConsumerTag($options['consumer_tag']);
            }

            if (isset($options['headers'])) {
                $message->setHeaders($options['headers']);
            }

            if (isset($options['properties'])) {
                $message->setProperties($options['properties']);
            }

            if (isset($options['attempts'])) {
                $message->setProperty(RabbitMQJob::ATTEMPT_COUNT_HEADERS_KEY, $options['attempts']);
            }

            $producer = $this->context->createProducer();
            if (isset($options['delay']) && $options['delay'] > 0) {
                $producer->setDeliveryDelay($options['delay'] * 1000);
            }

            $producer->send($topic, $message);

            return $message->getCorrelationId();
        } catch (\Exception $exception) {
            $this->reportConnectionError('pushRaw', $exception);

            return null;
        }
    }

    /** {@inheritdoc} */
    public function later($delay, $job, $data = '', $queue = null): ?string
    {
        return $this->pushRaw($this->createPayload($job, $queue, $data), $queue, ['delay' => $this->secondsUntil($delay)]);
    }

    /**
     * Release a reserved job back onto the queue.
     *
     * @param  \DateTimeInterface|\DateInterval|int $delay
     * @param  string|object $job
     * @param  mixed $data
     * @param  string $queue
     * @param  int $attempts
     * @return mixed
     */
    public function release($delay, $job, $data, $queue, $attempts = 0)
    {
        return $this->pushRaw($this->createPayload($job, $queue, $data), $queue, [
            'delay' => $this->secondsUntil($delay),
            'attempts' => $attempts,
        ]);
    }

    /** {@inheritdoc} */
    public function pop($queue = null)
    {
        try {
            /** @var AmqpQueue $amqpQueue */
            [$amqpQueue] = $this->declareEverything($queue);

            /** @var AmqpConsumer $amqpConsumer */
            $amqpConsumer = $this->context->createConsumer($amqpQueue);

            if ($message = $amqpConsumer->receiveNoWait()) {
                return new RabbitMQJob($this->container, $this, $amqpConsumer, $message);
            }
        } catch (\Throwable $exception) {
            $this->reportConnectionError('pop', $exception);
        }

        return null;
    }

    /**
     * Retrieves the correlation id, or a unique id.
     *
     * @return string
     */
    public function getCorrelationId(): string
    {
        return $this->correlationId ?: uniqid('', true);
    }

    /**
     * Sets the correlation id for a message to be published.
     *
     * @param string $id
     *
     * @return void
     */
    public function setCorrelationId(string $id): void
    {
        $this->correlationId = $id;
    }

    /**
     * @return AmqpContext
     */
    public function getContext(): AmqpContext
    {
        return $this->context;
    }

    /**
     * @param string|null $queueName
     *
     * @return array [Interop\Amqp\AmqpQueue, Interop\Amqp\AmqpTopic]
     */
    protected function declareEverything(?string $queueName = null): array
    {
        /** @var string $queueName */
        $queueName = $this->getQueueName($queueName);

        /** @var string $exchangeName */
        $exchangeName = $this->exchangeOptions['name'] ?: $queueName;

        $topic = $this->context->createTopic($exchangeName);

        $topic->setType($this->exchangeOptions['type']);
        $topic->setArguments($this->exchangeOptions['arguments']);

        if ($this->exchangeOptions['passive']) {
            $topic->addFlag(AmqpTopic::FLAG_PASSIVE);
        }
        if ($this->exchangeOptions['durable']) {
            $topic->addFlag(AmqpTopic::FLAG_DURABLE);
        }
        if ($this->exchangeOptions['auto_delete']) {
            $topic->addFlag(AmqpTopic::FLAG_AUTODELETE);
        }

        if ($this->exchangeOptions['declare'] && ! in_array($exchangeName, $this->declaredExchanges, true)) {
            try {
                $this->context->declareTopic($topic);
            } catch (AMQPChannelClosedException $e) {
                throw new AMQPChannelClosedException('Exchange declared with different arguments.', 0, $e);
            }

            $this->declaredExchanges[] = $exchangeName;
        }

        $queue = $this->context->createQueue($queueName);

        /** @var array $queueOptions */
        $queueOptions = Config::get("queue.connections.rabbitmq.options");

        $queueOptions = $queueOptions["queue_{$queueName}"] ?? $this->queueOptions;

        if ($queueOptions['arguments']) {
            $queue->setArguments(is_string($queueOptions['arguments']) ? json_decode($queueOptions['arguments'], true) : $queueOptions['arguments']);
        }
        if ($queueOptions['passive']) {
            $queue->addFlag(AmqpQueue::FLAG_PASSIVE);
        }
        if ($queueOptions['durable']) {
            $queue->addFlag(AmqpQueue::FLAG_DURABLE);
        }
        if ($queueOptions['exclusive']) {
            $queue->addFlag(AmqpQueue::FLAG_EXCLUSIVE);
        }
        if ($queueOptions['auto_delete']) {
            $queue->addFlag(AmqpQueue::FLAG_AUTODELETE);
        }

        if ($queueOptions['declare'] && ! in_array($queueName, $this->declaredQueues, true)) {
            try {
                $this->context->declareQueue($queue);
            } catch (AMQPChannelClosedException $e) {
                throw new AMQPChannelClosedException('Queue declared with different arguments.', 0, $e);
            }

            $this->declaredQueues[] = $queueName;
        }

        if ($queueOptions['bind']) {
            $this->context->bind(new AmqpBind($queue, $topic, $queue->getQueueName()));
        }

        return [$queue, $topic];
    }

    protected function getQueueName($queueName = null): string
    {
        return $queueName ?: $this->queueName;
    }

    protected function createPayloadArray($job, $queue, $data = ''): array
    {
        return array_merge(parent::createPayloadArray($job, $queue, $data), [
            'id' => $this->getRandomId(),
        ]);
    }

    /**
     * Get a random ID string.
     *
     * @return string
     */
    protected function getRandomId(): string
    {
        return Str::random(32);
    }

    /**
     * @param string $action
     * @param Throwable $e
     *
     * @throws RuntimeException
     */
    protected function reportConnectionError(string $action, Throwable $e): void
    {
        /** @var LoggerInterface $logger */
        $logger = $this->container['log'];

        $logger->error('AMQP error while attempting '.$action.': '.$e->getMessage());

        // If it's set to false, throw an error rather than waiting
        if ($this->sleepOnError === false) {
            throw new RuntimeException('Error writing data to the connection with RabbitMQ', null, $e);
        }

        // Sleep so that we don't flood the log file
        sleep($this->sleepOnError);
    }
}
