<?php

namespace Opifer\ContentBundle\Block;

/**
 * Interface BlockAdapterInterface
 *
 * Certain clients needs to know if they're working with adapter or
 * real block entities.
 *
 * @package Opifer\ContentBundle\Block
 */
interface BlockAdapterInterface
{

    /**
     * Return the Doctrine managed entity
     *
     * @return object
     */
    public function getEntity();

    /**
     * Set the Doctrine managed entity
     *
     * @param object $entity
     *
     * @return mixed
     */
    public function setEntity($entity);
}