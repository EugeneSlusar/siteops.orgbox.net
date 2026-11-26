<?php

declare(strict_types=1);

// OpenID Connect Discovery Document.
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require __DIR__ . '/../../../autoload.php';

\OrgBox\SiteOps\Http\OAuthRoute::run('oidc-configuration');
