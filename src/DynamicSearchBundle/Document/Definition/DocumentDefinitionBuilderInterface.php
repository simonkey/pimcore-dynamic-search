<?php

namespace DynamicSearchBundle\Document\Definition;

use DynamicSearchBundle\Normalizer\Resource\ResourceMetaInterface;

interface DocumentDefinitionBuilderInterface
{
    /**
     * @param string                $contextName
     * @param ResourceMetaInterface $resourceMeta
     *
     * @return bool
     */
    public function isApplicable(string $contextName, ResourceMetaInterface $resourceMeta);

    /**
     * @param DocumentDefinitionInterface $definition
     *
     * @return DocumentDefinitionInterface
     */
    public function buildDefinition(DocumentDefinitionInterface $definition);

}