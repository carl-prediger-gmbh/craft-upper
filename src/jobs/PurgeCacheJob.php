<?php namespace ostark\upper\jobs;

use Craft;
use craft\queue\BaseJob;
use ostark\upper\events\PurgeEvent;
use ostark\upper\Plugin;

/**
 * Class PurgeCache
 *
 * @package ostark\upper\jobs
 */
class PurgeCacheJob extends BaseJob
{
    /**
     * @var string tag
     */
    public $tag;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        if (!$this->tag) {
            return;
        }

        // Get registered purger
        $purger = Plugin::getInstance()->getPurger();
        Plugin::getInstance()->trigger(Plugin::EVENT_JOB_BEFORE_PURGE, new PurgeEvent(['tag' => $this->tag]));
        $purger->purgeTag($this->tag);
        Plugin::getInstance()->trigger(Plugin::EVENT_JOB_AFTER_PURGE, new PurgeEvent(['tag' => $this->tag]));
    }


    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return Craft::t('upper', 'Upper Purge: {tag}', ['tag' => $this->tag]);
    }
}
