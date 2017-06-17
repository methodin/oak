<?php
namespace Methodin\Oak\Annotations;

/**
* @Annotation
* @Target({"METHOD","PROPERTY"})
*/
class Bind
{
    /**
     * @Required
     */
    public $events;
    /**
     * @var array
     */
    public $emits = [];
}
