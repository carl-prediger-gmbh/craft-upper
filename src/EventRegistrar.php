<?php namespace ostark\upper;

use craft\services\Drafts;
use DateTime;

use yii\base\Event;

use Craft;
use craft\elements\db\ElementQuery;
use craft\events\DraftEvent;
use craft\events\ElementEvent;
use craft\events\ElementStructureEvent;
use craft\events\MoveElementEvent;
use craft\events\PopulateElementEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\SectionEvent;
use craft\events\TemplateEvent;
use craft\helpers\ElementHelper;
use craft\services\Elements;
use craft\services\Structures;
use craft\utilities\ClearCaches;
use craft\web\Response;
use craft\web\View;

use ostark\upper\events\CacheResponseEvent;
use ostark\upper\events\PurgeEvent;
use ostark\upper\jobs\PurgeCacheJob;

/**
 * Class EventRegistrar
 *
 * @package ostark\upper
 */
class EventRegistrar
{

    public static function registerUpdateEvents()
    {
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function ($event) {
            static::handleUpdateEvent($event);
        });
        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, function ($event) {
            static::handleUpdateEvent($event);
        });
        Event::on(Structures::class, Structures::EVENT_AFTER_MOVE_ELEMENT, function ($event) {
            static::handleUpdateEvent($event);
        });
        Event::on(Drafts::class, Drafts::EVENT_AFTER_APPLY_DRAFT, function ($event) {
            static::handleUpdateEvent($event);
        });
    }

    public static function registerFrontendEvents()
    {
        // No need to continue when in cli mode
        if (\Craft::$app instanceof \craft\console\Application) {
            return false;
        }

        // HTTP request object
        $request = \Craft::$app->getRequest();

        // Don't cache CP, LivePreview, Action, Non-GET requests
        if ($request->getIsCpRequest() ||
            $request->getIsLivePreview() ||
            $request->getIsActionRequest() ||
            !$request->getIsGet()
        ) {
            /** @var \ostark\upper\behaviors\CacheControlBehavior|\ostark\upper\behaviors\TagHeaderBehavior|Response  $response */
            $response = \Craft::$app->getResponse();
            $response->addCacheControlDirective('private');
            $response->addCacheControlDirective('no-cache');

            return false;
        }

        // Collect tags
        Event::on(ElementQuery::class, ElementQuery::EVENT_AFTER_POPULATE_ELEMENT, function (PopulateElementEvent $event) {

            // Don't collect MatrixBlock and User elements for now
            if (!Plugin::getInstance()->getSettings()->isCachableElement(get_class($event->element))) {
                return;
            }

            // Tag with GlobalSet handle
            if ($event->element instanceof \craft\elements\GlobalSet) {
                Plugin::getInstance()->getTagCollection()->add($event->element->handle);
            }

            // Add to collection
            Plugin::getInstance()->getTagCollection()->addTagsFromElement($event->row);

        });

        // Add the tags to the response header
        Event::on(View::class, View::EVENT_AFTER_RENDER_PAGE_TEMPLATE, function (TemplateEvent $event) {

            /** @var \yii\web\Response|\ostark\upper\behaviors\CacheControlBehavior|\ostark\upper\behaviors\TagHeaderBehavior $response */
            $response      = \Craft::$app->getResponse();
            $plugin        = Plugin::getInstance();
            $tagCollection = $plugin->getTagCollection();
            $tags          = $plugin->getTagCollection()->getAll();
            $settings      = $plugin->getSettings();
            $headers       = $response->getHeaders();

            // Make existing cache-control headers accessible
            $response->setCacheControlDirectiveFromString($headers->get('cache-control'));

            // Don't cache if private | no-cache set already
            if ($response->hasCacheControlDirective('private') || $response->hasCacheControlDirective('no-cache')) {
                $headers->set(Plugin::INFO_HEADER_NAME, 'BYPASS');

                return;
            }

            // MaxAge or defaultMaxAge?
            $maxAge = $response->getMaxAge() ?? $settings->defaultMaxAge;

            // Set Headers
            $maxBytes = $settings->maxBytesForCacheTagHeader;
            $maxedTags = $tagCollection->getUntilMaxBytes($maxBytes);
            $response->setTagHeader($settings->getTagHeaderName(), $maxedTags, $settings->getHeaderTagDelimiter());

            // Flag truncation
            if (count($tags) > count($maxedTags)) {
                $headers->set(Plugin::TRUNCATED_HEADER_NAME, (string) (count($tags) - count($maxedTags)));
            }

            $response->setSharedMaxAge($maxAge);
            $headers->set(Plugin::INFO_HEADER_NAME, "CACHED: " . date(\DateTime::ISO8601));

            $plugin->trigger($plugin::EVENT_AFTER_SET_TAG_HEADER, new CacheResponseEvent([
                    'tags'       => $tags,
                    'maxAge'     => $maxAge,
                    'requestUrl' => \Craft::$app->getRequest()->getUrl(),
                    'headers'    => $response->getHeaders()->toArray()
                ]
            ));
        });

    }


    public static function registerCpEvents()
    {
        // Register cache purge checkbox
        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            function (RegisterCacheOptionsEvent $event) {
                $driver           = ucfirst(Plugin::getInstance()->getSettings()->driver);
                $event->options[] = [
                    'key'    => 'upper-purge-all',
                    'label'  => \Craft::t('upper', 'Upper ({driver})', ['driver' => $driver]),
                    'action' => function () {
                        Plugin::getInstance()->getPurger()->purgeAll();
                    },
                ];
            }
        );
    }


    public static function registerFallback()
    {

        Event::on(Plugin::class, Plugin::EVENT_AFTER_SET_TAG_HEADER, function (CacheResponseEvent $event) {

            // not tagged?
            if (0 == count($event->tags)) {
                return;
            }

            // fulltext or array
            $tags = \Craft::$app->getDb()->getIsMysql()
                ? implode(" ", $event->tags)
                : str_replace(['[', ']'], ['{', '}'], json_encode($event->tags) ?: '[]');

            // in order to have a unique (collitions are possible) identifier by url with a fixed length
            $urlHash = md5($event->requestUrl);

            try {
                // Insert item
                \Craft::$app->getDb()->createCommand()
                    ->upsert(
                    // Table
                        Plugin::CACHE_TABLE,

                        // Identifier
                        ['urlHash' => $urlHash],

                        // Data
                        [
                            'urlHash' => $urlHash,
                            'url'     => $event->requestUrl,
                            'tags'    => $tags,
                            'headers' => json_encode($event->headers),
                            'siteId'  => \Craft::$app->getSites()->currentSite->id
                        ]
                    )
                    ->execute();
            } catch (\Exception $e) {
                \Craft::warning("Failed to register fallback.", "upper");
            }

        });

    }


    /**
     * @param \yii\base\Event $event
     */
    protected static function handleUpdateEvent(Event $event)
    {
        $tags = [];


        if ($event instanceof ElementEvent) {

            if (!Plugin::getInstance()->getSettings()->isCachableElement(get_class($event->element))) {
                return;
            }

            // Prevent purge on updates of drafts or revisions
            if (ElementHelper::isDraftOrRevision($event->element)) {
                return;
            }

            // Prevent purge on resaving
            if (property_exists($event->element, 'resaving') && $event->element->resaving === true) {
                return;
            }

            if ($event->element instanceof \craft\elements\GlobalSet && is_string($event->element->handle)) {
                $tags[] = $event->element->handle;
            } elseif ($event->element instanceof \craft\elements\Asset && $event->isNew) {
                $tags[] = (string)$event->element->volumeId;
            } else {
                if (isset($event->element->sectionId)) {
                    $tags[] = Plugin::TAG_PREFIX_SECTION . $event->element->sectionId;
                }
                if (!$event->isNew) {
                    $tags[] = Plugin::TAG_PREFIX_ELEMENT . $event->element->getId();
                }
            }
        }

        if ($event instanceof SectionEvent) {
            $tags[] = Plugin::TAG_PREFIX_SECTION . $event->section->id;
        }

        if ($event instanceof MoveElementEvent or $event instanceof ElementStructureEvent) {
            $tags[] = Plugin::TAG_PREFIX_STRUCTURE . $event->structureId;
        }

        if ($event instanceof DraftEvent) {
            $tags[] = Plugin::TAG_PREFIX_ELEMENT . $event->canonical->getId();
        }

        if (count($tags) === 0) {
            return;
        }

        if (\property_exists($event, 'element')) {
            $postDate = \property_exists($event->element, 'postDate') ? $event->element->postDate : null;
            $expiryDate = \property_exists($event->element, 'expiryDate') ? $event->element->expiryDate : null;
        } else {
            $postDate = null;
            $expiryDate = null;
        }

        foreach ($tags as $tag) {
            self::purgeCacheTag($tag, $postDate, $expiryDate);
        }
    }

    protected static function purgeCacheTag(string $tag, ?DateTime $validFrom = null, ?DateTime $validUntil = null)
    {
        $purgeEvent = self::createPurgeEvent($tag);

        Plugin::getInstance()->trigger(Plugin::EVENT_BEFORE_PURGE, $purgeEvent);

        $queue = Craft::$app->getQueue();
        $delay = $validFrom === null ? 0 : self::getDelayInSeconds($validFrom);
        if ($delay > 0) {
            $queue->delay($delay);
        }
        $queue->push(new PurgeCacheJob(['tag' => $purgeEvent->tag]));
        if ($validUntil !== null) {
            $delay = self::getDelayInSeconds($validUntil);
            if ($delay > 0) {
                $queue->delay($delay);
                $queue->push(new PurgeCacheJob(['tag' => $purgeEvent->tag]));
            }
        }

        Plugin::getInstance()->trigger(Plugin::EVENT_AFTER_PURGE, $purgeEvent);
    }

    private static function createPurgeEvent(string $tag): PurgeEvent
    {
        $tagCollection = Plugin::getInstance()->getTagCollection();
        return new PurgeEvent(['tag' => $tagCollection->prepareTag($tag)]);
    }

    private static function getDelayInSeconds(DateTime $dateTime): int
    {
        $delay = $dateTime->getTimestamp() - \time();
        if ($delay > 0) {
            return $delay;
        }
        return 0;
    }
}
