# Plugin tests

PHPUnit-based test suite for the WODO Bridge WordPress plugin.

## Prerequisites

- PHP 8.0+ matching `composer.json::require.php`.
- A local MySQL/MariaDB the WP test runner can `CREATE DATABASE` against.
- The WordPress test library extracted somewhere on disk.

## One-time setup

```sh
# 1. Install dev deps (PHPUnit 9, polyfills, Brain Monkey, Mockery).
composer install

# 2. Install the WP test suite. The conventional script:
bash bin/install-wp-tests.sh wodo_bridge_test root '' 127.0.0.1 latest

# This creates:
#   /tmp/wordpress-tests-lib/   (WP_TESTS_DIR)
#   /tmp/wordpress/             (WP source the test library boots)
#   wodo_bridge_test database (DROPPED + recreated each run)

# 3. Tell PHPUnit where the test library lives.
export WP_TESTS_DIR=/tmp/wordpress-tests-lib
```

If you don't have `bin/install-wp-tests.sh`, copy it from any modern WP plugin
template — it's a 60-line bootstrapper maintained by the community.

## Running tests

```sh
# Full suite.
composer test

# By category:
composer test:unit       # tests/Unit — pure PHP / mocked WP.
composer test:rest       # tests/Rest — boots WP, dispatches REST requests.
composer test:security   # tests/Security — pen-test scenarios PT-01..PT-15.

# Single test:
vendor/bin/phpunit tests/Unit/Auth/Scope_ManagerTest.php
```

## Layout

```
tests/
  bootstrap.php          Boots WP test suite + plugin autoloader.
  TestCase.php           Base class with create_token(), make_rest_request(),
                         assert_error_envelope() helpers.
  Fixtures/
    elementor-crypto-design.json   288 KB Elementor template export used by
                                   Elementor\Writer::validate /
                                   Page_Analyzer::study tests.

  Unit/                  Fast, isolated tests; no REST dispatch.
    Auth/
      Scope_ManagerTest.php
      Token_ServiceTest.php
    Lib/
      Activity_LoggerTest.php
      Rate_LimiterTest.php
      Request_ContextTest.php
    Webhooks/
      Url_ValidatorTest.php
      DispatcherTest.php
    Content/
      Block_CodecTest.php
    Bulk/
      HandlerTest.php

  Rest/                  REST integration tests (full WP boot).
    Auth_FlowTest.php    Bearer auth, scope-missing, revoked-token paths.
    PostsTest.php        list/get/create/update + meta allow-list.
    CptTest.php          Generic CPT discovery + writes.
    MediaTest.php        Read, upload, mime allow-list, SVG default-deny.
    ElementorTest.php    Kit/widgets/pages, validate against fixture.
    BulkTest.php         Per-item transactionality, partial success.
    WebhooksTest.php     Register, test endpoint, delivery row.
    Site_IdentityTest.php Ed25519 signature + payload shape.
    Activity_LogTest.php Every authenticated request logs an activity row.

  Security/              Threat-model regression scenarios.
    PT_01_RecursionBombTest.php
    PT_02_ElementCountBombTest.php
    PT_03_PostmetaClobberTest.php
    PT_04_ScopeBypassTest.php
    PT_05_SsrfTest.php
    PT_06_RateLimitTest.php
    PT_07_TraceIdTest.php
    PT_08_IdentityReplayTest.php
    PT_09_IpSpoofTest.php
    PT_10_AuthHeaderRedactedTest.php
    PT_11_SqlInjectionTest.php
    PT_12_PathTraversalTest.php
```

## Coverage

```sh
vendor/bin/phpunit --coverage-html coverage/
open coverage/index.html
```

Targets per `phpunit.xml.dist`:

- `src/Auth/`     ≥ 85 % lines
- `src/Webhooks/` ≥ 85 % lines
- `src/Lib/`      ≥ 80 % lines
- `src/Admin/` excluded — UI only.

## Notes on ENV

- `WB_ALLOW_INSECURE` is forced to `true` by the PHPUnit config so HTTPS
  enforcement (Auth_Filter::authenticate) doesn't 426 every request.
- `WB_TRUST_PROXY` is left unset for most tests; PT-09 explicitly defines and
  un-defines it to exercise the trust toggle.
