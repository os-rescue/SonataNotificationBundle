<?php

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\NotificationBundle\DependencyInjection;

use Sonata\EasyExtendsBundle\Mapper\DoctrineCollector;
use Sonata\NotificationBundle\Model\MessageInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class SonataNotificationExtension extends Extension
{
    /**
     * @var int
     */
    protected $amqpCounter = 0;

    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));

        /*
         * NEXT_MAJOR: Remove the check for ServiceClosureArgument as well as core_legacy.xml.
         */
        if (class_exists('Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument')) {
            $loader->load('core.xml');
        } else {
            $loader->load('core_legacy.xml');
        }

        if ('orm' === $config['db_driver']) {
            $loader->load('doctrine_orm.xml');
        } else {
            $loader->load('doctrine_mongodb.xml');
        }

        $loader->load('backend.xml');
        $loader->load('consumer.xml');
        $loader->load('selector.xml');
        $loader->load('event.xml');

        if ('mongodb' === $config['db_driver']) {
            $optimizeDefinition = $container->getDefinition('sonata.notification.event.doctrine_optimize');
            $optimizeDefinition->replaceArgument(0, new Reference('doctrine_mongodb'));

            $backendOptimizeDefinition = $container->getDefinition('sonata.notification.event.doctrine_backend_optimize');
            $backendOptimizeDefinition->replaceArgument(0, new Reference('doctrine_mongodb'));

            $selectorDefinition = $container->getDefinition('sonata.notification.erroneous_messages_selector');
            $selectorDefinition->replaceArgument(0, new Reference('doctrine_mongodb'));
        }

        if ($config['consumers']['register_default']) {
            $loader->load('default_consumers.xml');
        }

        $bundles = $container->getParameter('kernel.bundles');

        if (isset($bundles['FOSRestBundle']) && isset($bundles['NelmioApiDocBundle'])) {
            $loader->load('api_controllers.xml');
            $loader->load('api_form.xml');
        }

        // for now, only support for ORM
        if ($config['admin']['enabled'] && isset($bundles['SonataDoctrineORMAdminBundle'])) {
            $loader->load('admin.xml');
        }

        if (isset($bundles['LiipMonitorBundle'])) {
            $loader->load('checkmonitor.xml');
        }

        $this->checkConfiguration($config);

        $container->setAlias('sonata.notification.backend', $config['backend']);
        $container->setParameter('sonata.notification.backend', $config['backend']);

        $this->registerDoctrineMapping($config);
        $this->registerParameters($container, $config);
        $this->configureBackends($container, $config);
        $this->configureClass($container, $config);
        $this->configureListeners($container, $config);
        $this->configureAdmin($container, $config);
    }

    /**
     * @param ContainerBuilder $container
     * @param array            $config
     */
    public function configureClass(ContainerBuilder $container, $config)
    {
        // admin configuration
        $container->setParameter('sonata.notification.admin.message.entity', $config['class']['message']);

        // manager configuration
        $container->setParameter('sonata.notification.manager.message.entity', $config['class']['message']);
    }

    /**
     * @param ContainerBuilder $container
     * @param array            $config
     */
    public function configureAdmin(ContainerBuilder $container, $config)
    {
        $container->setParameter('sonata.notification.admin.message.class', $config['admin']['message']['class']);
        $container->setParameter('sonata.notification.admin.message.controller', $config['admin']['message']['controller']);
        $container->setParameter('sonata.notification.admin.message.translation_domain', $config['admin']['message']['translation']);
    }

    /**
     * @param ContainerBuilder $container
     * @param array            $config
     */
    public function registerParameters(ContainerBuilder $container, $config)
    {
        $container->setParameter('sonata.notification.message.class', $config['class']['message']);
        $container->setParameter('sonata.notification.admin.message.entity', $config['class']['message']);
    }

    /**
     * @param ContainerBuilder $container
     * @param array            $config
     */
    public function configureBackends(ContainerBuilder $container, $config)
    {
        // set the default value, will be erase if required
        if ('orm' === $config['db_driver']) {
            $container->setAlias('sonata.notification.manager.message', 'sonata.notification.manager.message.default');
        } else {
            $container->setAlias('sonata.notification.manager.message', 'sonata.notification.manager.mongodb.message.default');
        }

        if (isset($config['backends']['rabbitmq']) && $config['backend'] === 'sonata.notification.backend.rabbitmq') {
            $this->configureRabbitmq($container, $config);

            $container->removeDefinition('sonata.notification.backend.doctrine');
        } else {
            $container->removeDefinition('sonata.notification.backend.rabbitmq');
        }

        if (isset($config['backends']['doctrine']) && $config['backend'] === 'sonata.notification.backend.doctrine') {
            $checkLevel = array(
                MessageInterface::STATE_DONE => $config['backends']['doctrine']['states']['done'],
                MessageInterface::STATE_ERROR => $config['backends']['doctrine']['states']['error'],
                MessageInterface::STATE_IN_PROGRESS => $config['backends']['doctrine']['states']['in_progress'],
                MessageInterface::STATE_OPEN => $config['backends']['doctrine']['states']['open'],
            );

            $pause = $config['backends']['doctrine']['pause'];
            $maxAge = $config['backends']['doctrine']['max_age'];
            $batchSize = $config['backends']['doctrine']['batch_size'];
            if (!is_null($config['backends']['doctrine']['message_manager'])) {
                $container->setAlias('sonata.notification.manager.message', $config['backends']['doctrine']['message_manager']);
            }

            $this->configureDoctrineBackends($container, $config, $checkLevel, $pause, $maxAge, $batchSize);
        }
    }

    /**
     * @param array $config
     */
    public function registerDoctrineMapping(array $config)
    {
        $collector = DoctrineCollector::getInstance();

        $collector->addIndex($config['class']['message'], 'idx_state', array(
            'state',
        ));

        $collector->addIndex($config['class']['message'], 'idx_created_at', array(
            'created_at',
        ));
    }

    /**
     * @param array $config
     */
    protected function checkConfiguration(array $config)
    {
        if (isset($config['backends']) && count($config['backends']) > 1) {
            throw new \RuntimeException('more than one backend configured, you can have only one backend configuration');
        }

        if (!isset($config['backends']['rabbitmq']) && $config['backend'] === 'sonata.notification.backend.rabbitmq') {
            throw new \RuntimeException('Please configure the sonata_notification.backends.rabbitmq section');
        }

        if (!isset($config['backends']['doctrine']) && $config['backend'] === 'sonata.notification.backend.doctrine') {
            throw new \RuntimeException('Please configure the sonata_notification.backends.doctrine section');
        }
    }

    /**
     * @param ContainerBuilder $container
     * @param array            $config
     */
    protected function configureListeners(ContainerBuilder $container, array $config)
    {
        $ids = $config['iteration_listeners'];

        // this one clean the unit of work after every iteration
        // it must be set on any backend ...
        $ids[] = 'sonata.notification.event.doctrine_optimize';

        if (isset($config['backends']['doctrine']) && $config['backends']['doctrine']['batch_size'] > 1) {
            // if the backend is doctrine and the batch size > 1, then
            // the unit of work must be cleaned wisely to avoid any issue
            // while persisting entities
            $ids = array(
                'sonata.notification.event.doctrine_backend_optimize',
            );
        }

        $container->setParameter('sonata.notification.event.iteration_listeners', $ids);
    }

    /**
     * @param ContainerBuilder $container
     * @param array            $config
     * @param bool             $checkLevel
     * @param int              $pause
     * @param int              $maxAge
     * @param int              $batchSize
     *
     * @throws \RuntimeException
     */
    protected function configureDoctrineBackends(ContainerBuilder $container, array $config, $checkLevel, $pause, $maxAge, $batchSize)
    {
        $queues = $config['queues'];
        $qBackends = array();

        $definition = $container->getDefinition('sonata.notification.backend.doctrine');

        // no queue defined, set a default one
        if (count($queues) == 0) {
            $queues = array(array(
                'queue' => 'default',
                'default' => true,
                'types' => array(),
            ));
        }

        $defaultSet = false;
        $declaredQueues = array();

        foreach ($queues as $pos => &$queue) {
            if (in_array($queue['queue'], $declaredQueues)) {
                throw new \RuntimeException('The doctrine backend does not support 2 identicals queue name, please rename one queue');
            }

            $declaredQueues[] = $queue['queue'];

            // make the configuration compatible with old code and rabbitmq
            if (isset($queue['routing_key']) && strlen($queue['routing_key']) > 0) {
                $queue['types'] = array($queue['routing_key']);
            }

            if (empty($queue['types']) && $queue['default'] === false) {
                throw new \RuntimeException('You cannot declared a doctrine queue with no type defined with default = false');
            }

            if (!empty($queue['types']) && $queue['default'] === true) {
                throw new \RuntimeException('You cannot declared a doctrine queue with types defined with default = true');
            }

            $id = $this->createDoctrineQueueBackend($container, $definition->getArgument(0), $checkLevel, $pause, $maxAge, $batchSize, $queue['queue'], $queue['types']);
            $qBackends[$pos] = array(
                'types' => $queue['types'],
                'backend' => new Reference($id),
            );

            if ($queue['default'] === true) {
                if ($defaultSet === true) {
                    throw new \RuntimeException('You can only set one doctrine default queue in your sonata notification configuration.');
                }

                $defaultSet = true;
                $defaultQueue = $queue['queue'];
            }
        }

        if ($defaultSet === false) {
            throw new \RuntimeException('You need to specify a valid default queue for the doctrine backend!');
        }

        $definition
            ->replaceArgument(1, $queues)
            ->replaceArgument(2, $defaultQueue)
            ->replaceArgument(3, $qBackends)
        ;
    }

    /**
     * @param ContainerBuilder $container
     * @param string           $manager
     * @param bool             $checkLevel
     * @param int              $pause
     * @param int              $maxAge
     * @param int              $batchSize
     * @param string           $key
     * @param array            $types
     *
     * @return string
     */
    protected function createDoctrineQueueBackend(ContainerBuilder $container, $manager, $checkLevel, $pause, $maxAge, $batchSize, $key, array $types = array())
    {
        if ($key == '') {
            $id = 'sonata.notification.backend.doctrine.default_'.$this->amqpCounter++;
        } else {
            $id = 'sonata.notification.backend.doctrine.'.$key;
        }

        $definition = new Definition('Sonata\NotificationBundle\Backend\MessageManagerBackend', array($manager, $checkLevel, $pause, $maxAge, $batchSize, $types));
        $definition->setPublic(false);

        $container->setDefinition($id, $definition);

        return $id;
    }

    /**
     * @param ContainerBuilder $container
     * @param array            $config
     */
    protected function configureRabbitmq(ContainerBuilder $container, array $config)
    {
        $queues = $config['queues'];
        $connection = $config['backends']['rabbitmq']['connection'];
        $baseExchange = $config['backends']['rabbitmq']['exchange'];
        $amqBackends = array();

        if (count($queues) == 0) {
            $queues = array(array(
                'queue' => 'default',
                'default' => true,
                'routing_key' => '',
                'recover' => false,
                'dead_letter_exchange' => null,
                'dead_letter_routing_key' => null,
                'ttl' => null,
            ));
        }

        $deadLetterRoutingKeys = $this->getQueuesParameters('dead_letter_routing_key', $queues);
        $routingKeys = $this->getQueuesParameters('routing_key', $queues);

        foreach ($deadLetterRoutingKeys as $key) {
            if (!in_array($key, $routingKeys)) {
                throw new \RuntimeException(sprintf(
                    'You must configure the queue having the routing_key "%s" same as dead_letter_routing_key', $key
                ));
            }
        }

        $declaredQueues = array();

        $defaultSet = false;
        foreach ($queues as $pos => $queue) {
            if (in_array($queue['queue'], $declaredQueues)) {
                throw new \RuntimeException('The RabbitMQ backend does not support 2 identicals queue name, please rename one queue');
            }

            $declaredQueues[] = $queue['queue'];

            if ($queue['dead_letter_routing_key']) {
                if (is_null($queue['dead_letter_exchange'])) {
                    throw new \RuntimeException(
                        'dead_letter_exchange must be configured when dead_letter_routing_key is set'
                    );
                }
            }

            if (in_array($queue['routing_key'], $deadLetterRoutingKeys)) {
                $exchange = $this->getAMQPDeadLetterExchangeByRoutingKey($queue['routing_key'], $queues);
            } else {
                $exchange = $baseExchange;
            }

            $id = $this->createAMQPBackend(
                $container,
                $exchange,
                $queue['queue'],
                $queue['recover'],
                $queue['routing_key'],
                $queue['dead_letter_exchange'],
                $queue['dead_letter_routing_key'],
                $queue['ttl']
            );

            $amqBackends[$pos] = array(
                'type' => $queue['routing_key'],
                'backend' => new Reference($id),
            );

            if ($queue['default'] === true) {
                if ($defaultSet === true) {
                    throw new \RuntimeException('You can only set one rabbitmq default queue in your sonata notification configuration.');
                }
                $defaultSet = true;
                $defaultQueue = $queue['routing_key'];
            }
        }

        if ($defaultSet === false) {
            throw new \RuntimeException('You need to specify a valid default queue for the rabbitmq backend!');
        }

        $container->getDefinition('sonata.notification.backend.rabbitmq')
            ->replaceArgument(0, $connection)
            ->replaceArgument(1, $queues)
            ->replaceArgument(2, $defaultQueue)
            ->replaceArgument(3, $amqBackends)
        ;
    }

    /**
     * @param ContainerBuilder $container
     * @param string           $exchange
     * @param string           $name
     * @param string           $recover
     * @param string           $key
     * @param string           $deadLetterExchange
     * @param string           $deadLetterRoutingKey
     * @param int|null         $ttl
     *
     * @return string
     */
    protected function createAMQPBackend(ContainerBuilder $container, $exchange, $name, $recover, $key = '', $deadLetterExchange = null, $deadLetterRoutingKey = null, $ttl = null)
    {
        $id = 'sonata.notification.backend.rabbitmq.'.$this->amqpCounter++;

        $definition = new Definition(
            'Sonata\NotificationBundle\Backend\AMQPBackend',
            array(
                $exchange,
                $name,
                $recover,
                $key,
                $deadLetterExchange,
                $deadLetterRoutingKey,
                $ttl,
            )
        );
        $definition->setPublic(false);
        $container->setDefinition($id, $definition);

        return $id;
    }

    /**
     * @param string $name
     * @param array  $queues
     *
     * @return string[]
     */
    private function getQueuesParameters($name, array $queues)
    {
        $params = array_unique(array_map(function ($q) use ($name) {
            return $q[$name];
        }, $queues));

        $idx = array_search(null, $params);
        if ($idx !== false) {
            unset($params[$idx]);
        }

        return $params;
    }

    /**
     * @param string $key
     * @param array  $queues
     *
     * @return string
     */
    private function getAMQPDeadLetterExchangeByRoutingKey($key, array $queues)
    {
        foreach ($queues as $queue) {
            if ($queue['dead_letter_routing_key'] === $key) {
                return $queue['dead_letter_exchange'];
            }
        }
    }
}
