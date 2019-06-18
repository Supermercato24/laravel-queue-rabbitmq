<?php declare(strict_types=1);

namespace VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Connectors;

use Illuminate\Support\Arr;
use Interop\Amqp\AmqpContext;
use Illuminate\Contracts\Queue\Queue;
use Interop\Amqp\AmqpConnectionFactory;
use Enqueue\AmqpTools\DelayStrategyAware;
use Illuminate\Contracts\Events\Dispatcher;
use Enqueue\AmqpTools\RabbitMqDlxDelayStrategy;
use Illuminate\Queue\Connectors\ConnectorInterface;
use LogicException;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;
use Interop\Amqp\AmqpConnectionFactory as InteropAmqpConnectionFactory;
use Enqueue\AmqpLib\AmqpConnectionFactory as EnqueueAmqpConnectionFactory;
use \ReflectionException;
use \ReflectionClass;

/**
 * Class RabbitMQConnector
 *
 * @package VladimirYuldashev\LaravelQueueRabbitMQ\Queue\Connectors
 */
class RabbitMQConnector implements ConnectorInterface
{
    /**
     * @var Dispatcher
     */
    private $dispatcher;

    /**
     * RabbitMQConnector constructor.
     *
     * @param Dispatcher $dispatcher
     */
    public function __construct(Dispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Establish a queue connection.
     *
     * @param array $config
     *
     * @return Queue
     * @throws ReflectionException
     */
    public function connect(array $config): Queue
    {
        $factoryClass = Arr::get($config, 'factory_class', EnqueueAmqpConnectionFactory::class);

        if (! class_exists($factoryClass) || ! (new ReflectionClass($factoryClass))->implementsInterface(InteropAmqpConnectionFactory::class)) {
            throw new LogicException(sprintf('The factory_class option has to be valid class that implements "%s"', InteropAmqpConnectionFactory::class));
        }

        /** @var AmqpConnectionFactory $factory */
        $factory = new $factoryClass([
            'dsn' => Arr::get($config, 'dsn'),
            'host' => Arr::get($config, 'host', '127.0.0.1'),
            'port' => Arr::get($config, 'port', 5672),
            'user' => Arr::get($config, 'login', 'guest'),
            'pass' => Arr::get($config, 'password', 'guest'),
            'vhost' => Arr::get($config, 'vhost', '/'),
            'ssl_on' => Arr::get($config, 'ssl_params.ssl_on', false),
            'ssl_verify' => Arr::get($config, 'ssl_params.verify_peer', true),
            'ssl_cacert' => Arr::get($config, 'ssl_params.cafile'),
            'ssl_cert' => Arr::get($config, 'ssl_params.local_cert'),
            'ssl_key' => Arr::get($config, 'ssl_params.local_key'),
            'ssl_passphrase' => Arr::get($config, 'ssl_params.passphrase'),
        ]);

        if ($factory instanceof DelayStrategyAware) {
            $factory->setDelayStrategy(new RabbitMqDlxDelayStrategy());
        }

        /** @var AmqpContext $context */
        $context = $factory->createContext();

        return new RabbitMQQueue($context, $config);
    }
}
