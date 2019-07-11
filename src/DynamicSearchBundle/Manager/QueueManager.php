<?php

namespace DynamicSearchBundle\Manager;

use DynamicSearchBundle\Configuration\ConfigurationInterface;
use DynamicSearchBundle\Context\ContextDataInterface;
use DynamicSearchBundle\Exception\NormalizerException;
use DynamicSearchBundle\Logger\LoggerInterface;
use DynamicSearchBundle\Normalizer\Resource\ResourceMetaInterface;
use DynamicSearchBundle\Normalizer\ResourceNormalizerInterface;
use DynamicSearchBundle\Queue\Data\Envelope;
use DynamicSearchBundle\Transformer\Container\ResourceContainer;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject;
use Pimcore\Model\Document;
use Pimcore\Model\Tool\TmpStore;

class QueueManager implements QueueManagerInterface
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ConfigurationInterface
     */
    protected $configuration;

    /**
     * @var NormalizerManagerInterface
     */
    protected $normalizerManager;

    /**
     * @param LoggerInterface            $logger
     * @param ConfigurationInterface     $configuration
     * @param NormalizerManagerInterface $normalizerManager
     */
    public function __construct(
        LoggerInterface $logger,
        ConfigurationInterface $configuration,
        NormalizerManagerInterface $normalizerManager
    ) {
        $this->logger = $logger;
        $this->configuration = $configuration;
        $this->normalizerManager = $normalizerManager;
    }

    /**
     * {@inheritDoc}
     */
    public function addToQueue(string $contextName, string $dispatcher, string $resourceType, int $resourceId, array $options)
    {
        $envelope = null;

        if (!in_array($dispatcher, ContextDataInterface::ALLOWED_QUEUE_DISPATCH_TYPES)) {
            $this->logger->error(
                sprintf('Wrong dispatch type "%s" for queue. Allowed types are: %s', $dispatcher, join(', ', ContextDataInterface::ALLOWED_QUEUE_DISPATCH_TYPES)),
                'queue',
                $contextName
            );
            return;
        }

        if (!in_array($resourceType, self::ALLOWED_QUEUE_TYPES)) {
            $this->logger->error(
                sprintf('Wrong queue type "%s" for queue. Allowed queue types are: %s', $dispatcher, join(', ', self::ALLOWED_QUEUE_TYPES)),
                'queue',
                $contextName
            );
            return;
        }

        try {
            $envelope = $this->generateJob($contextName, $dispatcher, $resourceType, $resourceId, $options);
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Error while adding data to queue. Message was: %s', $e->getMessage()),
                'queue',
                $contextName
            );
        }

        if (!$envelope instanceof Envelope) {
            return;
        }

        $this->logger->debug(
            sprintf('Envelope with id %s successfully added to queue', $envelope->getId()),
            'queue',
            $contextName
        );
    }

    /**
     * {@inheritDoc}
     */
    public function clearQueue()
    {
        try {
            $activeJobs = $this->getActiveJobs();
            $this->logger->debug(sprintf('data queue cleared. Affected jobs: %d', (is_array($activeJobs) ? count($activeJobs) : 0)), 'queue', 'maintenance');
            foreach ($activeJobs as $envelope) {
                TmpStore::delete($envelope->getId());
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Error while clearing queue. Message was: %s', $e->getMessage()), 'queue', 'maintenance');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function hasActiveJobs()
    {
        $activeJobs = TmpStore::getIdsByTag(self::QUEUE_IDENTIFIER);

        if (!is_array($activeJobs)) {
            return false;
        }

        return count($activeJobs) > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveJobs()
    {
        $activeJobs = TmpStore::getIdsByTag(self::QUEUE_IDENTIFIER);

        if (!is_array($activeJobs)) {
            return [];
        }

        $jobs = [];
        foreach ($activeJobs as $processId) {

            $process = $this->getJob($processId);
            if (!$process instanceof TmpStore) {
                continue;
            }

            $jobs[] = $process;
        }

        return $jobs;
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveEnvelopes()
    {
        $jobs = $this->getActiveJobs();

        $existingKeys = [];
        $filteredEnvelopes = [];

        /*
         * Filter Jobs:
         * -> first sort jobs by date (ASC) to receive latest entries first!
         * -> create sub array for each context and dispatch type: stack[ context ][ dispatch type ][]
         * -> only add resource once per "context - resourceType - resourceId"
         * -> only return envelope
         */

        usort($jobs, function ($a, $b) {
            /**
             * @var $a TmpStore
             * @var $b TmpStore
             */
            if ($a->getDate() === $b->getDate()) {
                return 0;
            }

            return $a->getDate() < $b->getDate() ? 1 : -1;
        });

        /** @var TmpStore $job */
        foreach ($jobs as $job) {

            /** @var Envelope $envelope */
            $envelope = $job->getData();
            $contextName = $envelope->getContextName();
            $dispatcher = $envelope->getDispatcher();

            $key = sprintf('%s_%s_%s', $contextName, $envelope->getResourceType(), $envelope->getResourceId());

            if (in_array($key, $existingKeys, true)) {
                $this->deleteJob($envelope);
                continue;
            }

            if (!isset($filteredEnvelopes[$contextName])) {
                $filteredEnvelopes[$contextName] = [];
            }
            if (!isset($filteredEnvelopes[$contextName])) {
                $filteredEnvelopes[$contextName][$dispatcher] = [];
            }

            $filteredEnvelopes[$contextName][$dispatcher][] = $envelope;
            $existingKeys[] = $key;
        }

        return $filteredEnvelopes;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteJob(Envelope $envelope)
    {
        try {
            TmpStore::delete($envelope->getId());
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Could not delete queued job with id %s', $envelope->getId()), 'queue', $envelope->getContextName());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getResource(string $resourceType, int $resourceId)
    {
        $object = null;
        switch ($resourceType) {
            case 'asset':
                $object = Asset::getById($resourceId);
                break;
            case 'document':
                $object = Document::getById($resourceId);
                break;
            case 'object':
                $object = DataObject::getById($resourceId);
                break;
        }

        return $object;
    }

    /**
     * @param string $contextName
     * @param string $dispatcher
     * @param string $resourceType
     * @param int    $resourceId
     * @param array  $options
     *
     * @return Envelope
     */
    protected function generateJob(string $contextName, string $dispatcher, string $resourceType, int $resourceId, array $options)
    {
        $jobId = $this->getJobId();

        if ($dispatcher === ContextDataInterface::CONTEXT_DISPATCH_TYPE_DELETE) {
            $removableDocuments = $this->assertRemovableDocuments($contextName, $resourceType, $resourceId);
            if (count($removableDocuments) === 0) {
                $this->logger->error(
                    sprintf('unable to assert resource ids for pimcore element "%s-%s" no queue job will be generated.',
                        $resourceType, $resourceId),
                    'queue', $contextName
                );
                return null;
            } else {
                $options['removable_documents'] = $removableDocuments;
            }
        }

        $envelope = new Envelope($jobId, $contextName, $dispatcher, $resourceType, $resourceId, $options);

        TmpStore::add($jobId, $envelope, self::QUEUE_IDENTIFIER);

        return $envelope;
    }

    /**
     * @param string $contextName
     * @param string $resourceType
     * @param int    $resourceId
     *
     * @return array
     */
    protected function assertRemovableDocuments(string $contextName, string $resourceType, int $resourceId)
    {
        $contextDefinition = $this->configuration->getContextDefinition(ContextDataInterface::CONTEXT_DISPATCH_TYPE_DELETE, $contextName);

        try {
            $resourceNormalizer = $this->normalizerManager->getResourceNormalizer($contextDefinition);
        } catch (NormalizerException $e) {
            $this->logger->debug(
                sprintf(
                    'Unable to load resource normalizer "%s". Error was: %s. Skipping...',
                    $contextDefinition->getResourceNormalizerName(), $e->getMessage()
                ), 'queue', $contextName
            );

            return [];
        }

        if (!$resourceNormalizer instanceof ResourceNormalizerInterface) {
            $this->logger->error(sprintf('Could not load resource normalizer to determinate deletion ids'), 'queue', $contextName);
        }

        $resource = $this->getResource($resourceType, $resourceId);
        $transformedResourceContainer = new ResourceContainer($resource);

        try {
            $normalizedResourceStack = $resourceNormalizer->normalizeToResourceStack($contextDefinition, $transformedResourceContainer);
        } catch (NormalizerException $e) {
            $this->logger->error(
                sprintf(
                    'Error while generating normalized resource stack with identifier "%s". Error was: %s. Skipping...',
                    $contextDefinition->getResourceNormalizerName(),
                    $e->getMessage()
                ),
                'queue', $contextName
            );

            return [];
        }

        $resourceMeta = [];
        foreach ($normalizedResourceStack as $normalizedResource) {
            $resourceMeta[] = $normalizedResource->getResourceMeta();
        }

        if (count($resourceMeta) > 0) {
            $this->logger->debug(
                sprintf('preparing pimcore element pimcore element "%s-%s" with resource ids "%s" for deletion',
                    $resourceType, $resourceId, join(', ', array_map(function (ResourceMetaInterface $el) {
                        return $el->getDocumentId();
                    }, $resourceMeta))),
                'queue', $contextName
            );
        }

        return $resourceMeta;
    }

    /**
     * @param $processId
     *
     * @return TmpStore|null
     */
    protected function getJob($processId)
    {
        $job = null;
        try {
            $job = TmpStore::get($processId);
        } catch (\Exception $e) {
            return null;
        }

        return $job;
    }

    /**
     * @return string
     */
    protected function getJobId()
    {
        return uniqid('dynamic-search-envelope-');
    }

}
