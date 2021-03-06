<?php
namespace x3tech\LaravelShipper\Builder\BuildStep;

use x3tech\LaravelShipper\CompatBridge;
use x3tech\LaravelShipper\SupportReporter;
use x3tech\LaravelShipper\DockerCompose\Definition;
use x3tech\LaravelShipper\DockerCompose\Container;

/**
 * Add queue+worker containers definition to docker-compose.yml for supported queue drivers
 *
 * @see DockerComposeBuildStepInterface
 */
class DockerComposeQueueBuildStep extends DockerComposeVolumesBuildStep
{
    /**
     * @var x3tech\LaravelShipper\CompatBridge
     */
    protected $compat;

    protected static $supported = array(
        'beanstalkd' => 'addBeanstalkd'
    );

    public function __construct(
        CompatBridge $compat,
        SupportReporter $supportReporter
    ) {
        $this->compat = $compat;

        array_map(
            array($supportReporter, 'addSupportedQueue'),
            array_keys(static::$supported)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function run(Definition $definition)
    {
        $conn = $this->getConnection();
        if (!$this->isSupported($conn)) {
            return;
        }

        $queue = $this->getQueueContainer($conn);
        $definition->addContainer($queue);
        $definition->getContainer('app')->addLink($queue);

        if ($conn['driver'] !== 'sync') {
            $this->addWorker($definition, $conn);
        }
    }

    /**
     * @param array $conn
     *
     * @return Container
     */
    protected function getQueueContainer(array $conn)
    {
        $method = self::$supported[$conn['driver']];
        return $this->$method($conn);
    }

    /**
     * Returns whether the queue driver is supported
     *
     * @param array $conn
     *
     * @return bool
     */
    protected function isSupported(array $conn)
    {
        return array_key_exists($conn['driver'], self::$supported);
    }

    /**
     * Get the configured default connection
     *
     * @return array
     */
    protected function getConnection()
    {
        $queueConfig = $this->compat->getConfig('queue');
        return $queueConfig['connections'][$queueConfig['default']];
    }

    /**
     * Add Beanstalkd container to the docker-compose.yml structure
     *
     * @param array $conn Queue connection config
     */
    protected function addBeanstalkd(array $conn)
    {
        $queue = new Container('queue');
        $queue->setImage('kdihalas/beanstalkd');

        return $queue;
    }

    /**
     * Add a queue worker to the definition
     *
     * @param Definition $definition
     * @param array $conn
     */
    protected function addWorker(Definition $definition, array $conn)
    {
        $env = $this->compat->getEnvironment();

        $worker = new Container('worker');
        $worker->setBuild('.');
        $worker->setCommand(array('/var/www/artisan', 'queue:listen'));
        $worker->setEnvironment(array(
            'APP_ENV' => $env
        ));
        $worker->addLink($definition->getContainer('queue'));

        if ($definition->getContainer('db')) {
            $worker->addLink($definition->getContainer('db'));
        }

        $this->addVolumes($worker, $this->compat);
        $definition->addContainer($worker);
    }
}
