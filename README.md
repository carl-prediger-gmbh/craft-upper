<img src="https://github.com/Carl-Prediger-GmbH/craft-upper/blob/master/resources/upper.png" height="80px"/>

Upper speeds up Craft dramatically using a **Cache Proxy** in front of your webserver. 

The Plugin adds the neccessary `Cache-Control` and `XKEY/Surrogate-Key/Cache-Tag` headers to your pages. 
When Entries or Sections get updated in the Control Panel it takes care of the cache invalidation. 

If you need an introduction to HTTP Caching, I highly recommend [this article](https://blog.fortrabbit.com/mastering-http-caching). 

## Sponsor

Development happens in my free time, but also during working hours. 
Thanks [fortrabbit.com](https://www.fortrabbit.com/craft-hosting)!

If you are looking additional integrations (Cache Drivers), feel free to contribute or [raise an issue](https://github.com/Carl-Prediger-GmbH/craft-upper/issues).

## Supported Cache Drivers

* [KeyCDN](https://www.keycdn.com) (CDN/SaaS)
* [Fastly](https://www.fastly.com) (CDN/SaaS)
* [Cloudflare](https://www.cloudflare.com) (CDN/SaaS)
* Varnish with XKEY support (your own proxy)
* Dummy (does nothing)

## Installation

1. Clone this repository into your plugins folder `plugins\craft-upper`
2. Add the plugin to your composer.json
```
"repositories": [
    {
        "type": "path",
        "url": "plugins/craft-upper"
    },
    ...
```
3. Install plugin with this command `php craft plugin/install upper` or in the Craft CP under Settings > Plugins
4. A new configuration file gets generated automatically in `your-project/config/upper.php`.

### Fastly Setup

```
UPPER_DRIVER=fastly
FASTLY_API_TOKEN=<REPLACE-ME>
FASTLY_SERVICE_ID=<REPLACE-ME>
FASTLY_DOMAIN=http://<REPLACE-ME>
```

### KeyCDN Setup

```
UPPER_DRIVER=keycdn
KEYCDN_API_KEY=<REPLACE-ME>
KEYCDN_ZONE_URL=<REPLACE-ME>.kxcdn.com
KEYCDN_ZONE_ID=<REPLACE-ME>
```

### Cloudflare Setup

```
UPPER_DRIVER=cloudflare
CLOUDFLARE_API_TOKEN=<REPLACE-ME>
CLOUDFLARE_ZONE_ID=<REPLACE-ME>
CLOUDFLARE_DOMAIN=https://<REPLACE-ME>
```

By default, Cloudflare's CDN  does not cache HTML content. You need to create a [**Cache Level: Cache Everything**](https://support.cloudflare.com/hc/en-us/articles/202775670-How-Do-I-Tell-Cloudflare-What-to-Cache-) Page Rule to enable caching for "pages".

If you don't use Cloudflare Enterprise with native `Cache-Tag` support, make sure to enable `useLocalTags` in your `config/upper.php` file (default), otherwise disable it.

You can generate a token in the Cloudflare dashboard. You want to create a custom token with the "Zone.Cache Purge" permission that is restricted to the DNS zone(s) you wish to clear Cloudflare's cache for.

 
### Varnish Setup
Varnish URL supports multiple servers, separate with comma. E.g `http://1.1.1.1,http://2.2.2.2`
```
UPPER_DRIVER=varnish
VARNISH_URL=<REPLACE-ME>
```

### Tuning

In a template, you can fully disable caching like so:

```
{% do upper.cache.never() %}
```

…which is a shorthand for:

```
{% header "Cache-Control: private, no-cache" %}
```

By default the `defaultMaxAge` config setting will be used to determine the cache duration to set. This can be overridden in a template:

```
{% do upper.cache.for('5 minutes') %}
```

…which is shorthand for:

```
{% header "Cache-Control: public, s-maxage=300" %}
```

### Performance results
![example](https://github.com/Carl-Prediger-GmbH/craft-upper/blob/master/resources/preformance.png)

### Cache Tag Headers
![example](https://github.com/Carl-Prediger-GmbH/craft-upper/blob/master/resources/response-header.png)


## Disclaimer

Even if the name of the plugin and some wordings are intentional, the author does not glorify any drug abuse. 🍻
The plugin is inspired by the [joshangell/Falcon](https://github.com/joshangell/Falcon).
