<?php namespace prediger\upper\events;

use yii\base\Event;

/**
 * Class PurgeEvent
 *
 * @package prediger\upper\events
 */
class PurgeEvent extends Event
{
    // Properties
    // =========================================================================

    /**
    * @var string tag
    */
    public $tag;

}
