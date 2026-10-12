<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('test_inertia', '/test')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'index']);

    $routes->add('test_inertia_redirect', '/test/redirect')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'redirect'])
        ->methods(['POST', 'PUT', 'PATCH', 'DELETE']);

    $routes->add('test_inertia_shared', '/test/shared')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'shared']);

    $routes->add('test_inertia_partial', '/test/partial')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'partial']);

    $routes->add('test_inertia_optional', '/test/optional')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'optional']);

    $routes->add('test_inertia_merge', '/test/merge')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'merge']);

    $routes->add('test_inertia_always', '/test/always')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'always']);

    $routes->add('test_inertia_defer', '/test/defer')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'defer']);

    $routes->add('test_inertia_once_prop', '/test/once-prop')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'onceProp']);

    $routes->add('test_inertia_clear_history', '/test/clear-history')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'clearHistory']);

    $routes->add('test_inertia_clear_history_redirect', '/test/clear-history-redirect')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'clearHistoryRedirect']);

    $routes->add('test_inertia_encrypt_history', '/test/encrypt-history')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'encryptHistory']);

    $routes->add('test_inertia_preserve_fragment', '/test/preserve-fragment')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'preserveFragment']);

    $routes->add('test_inertia_preserve_fragment_redirect', '/test/preserve-fragment-redirect')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'preserveFragmentRedirect']);

    $routes->add('test_inertia_redirect_with_fragment', '/test/redirect-with-fragment')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'redirectWithFragment'])
        ->methods(['PUT']);

    $routes->add('test_inertia_match_props_on', '/test/match-props-on')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'matchPropsOn']);

    $routes->add('test_inertia_match_props_on_multi', '/test/match-props-on-multi')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'matchPropsOnMulti']);

    $routes->add('test_inertia_match_props_on_deep', '/test/match-props-on-deep')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'matchPropsOnDeep']);

    $routes->add('test_inertia_scroll_props', '/test/scroll-props')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'scrollProps']);

    $routes->add('test_inertia_scroll_props_prepend', '/test/scroll-props-prepend')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'scrollPropsPrepend']);

    $routes->add('test_inertia_scroll_props_intent', '/test/scroll-props-intent')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'scrollPropsIntent']);

    $routes->add('test_inertia_scroll_props_intent_prepend', '/test/scroll-props-intent-prepend')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'scrollPropsIntentPrepend']);

    $routes->add('test_inertia_scroll_props_full', '/test/scroll-props-full')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'scrollPropsFull']);

    $routes->add('test_inertia_flash_direct', '/test/flash-direct')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'flashDirect']);

    $routes->add('test_inertia_flash_target', '/test/flash-target')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'flashTarget']);

    $routes->add('test_inertia_flash_redirect', '/test/flash-redirect')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'flashRedirect'])
        ->methods(['PUT']);

    $routes->add('test_inertia_location', '/test/location')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'location']);

    $routes->add('test_inertia_location_internal', '/test/location-internal')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'locationInternal']);

    $routes->add('test_inertia_defer_merge', '/test/defer-merge')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'deferMerge']);

    $routes->add('test_inertia_defer_deep_merge', '/test/defer-deep-merge')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'deferDeepMerge']);

    $routes->add('test_inertia_defer_merge_match_on', '/test/defer-merge-match-on')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'deferMergeMatchOn']);

    $routes->add('test_inertia_scroll_defer', '/test/scroll-defer')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'scrollDefer']);

    $routes->add('test_inertia_scroll_defer_group', '/test/scroll-defer-group')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'scrollDeferGroup']);

    $routes->add('test_validation_errors', '/test/validation-errors')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'validationErrors']);

    $routes->add('test_validation_errors_target', '/test/validation-errors-target')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'validationErrorsTarget']);

    $routes->add('test_validation_errors_redirect', '/test/validation-errors-redirect')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'validationErrorsRedirect'])
        ->methods(['PUT']);

    $routes->add('test_validation_errors_named_bag', '/test/validation-errors-named-bag')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'validationErrorsNamedBag']);

    $routes->add('test_validation_errors_override', '/test/validation-errors-override')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'validationErrorsOverride']);

    $routes->add('test_merge_at_path', '/test/merge-at-path')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'mergeAtPath']);

    $routes->add('test_prepend_at_path', '/test/prepend-at-path')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'prependAtPath']);

    $routes->add('test_merge_path_mixed', '/test/merge-path-mixed')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'mergePathMixed']);

    $routes->add('test_defer_at_path', '/test/defer-at-path')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'deferAtPath']);

    $routes->add('test_defer_once', '/test/defer-once')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'deferOnce']);

    $routes->add('test_optional_once', '/test/optional-once')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'optionalOnce']);

    $routes->add('test_shared_props_emit', '/test/shared-props-emit')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'sharedPropsEmit']);

    $routes->add('test_shared_props_empty', '/test/shared-props-empty')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'sharedPropsEmpty']);

    $routes->add('test_map_request_payload_form', '/test/map-request-payload')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'mapRequestPayloadForm'])
        ->methods(['GET']);

    $routes->add('test_map_request_payload_submit', '/test/map-request-payload')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'mapRequestPayloadSubmit'])
        ->methods(['POST']);

    $routes->add('test_map_request_payload_stateless', '/test/map-request-payload-stateless')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'mapRequestPayloadSubmit'])
        ->defaults(['_stateless' => true])
        ->methods(['POST']);

    $routes->add('test_map_query_string_search', '/test/map-query-string')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'mapQueryStringSearch'])
        ->methods(['GET']);

    $routes->add('test_throw_validation_failed', '/test/throw-validation-failed')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'throwValidationFailed'])
        ->methods(['POST']);

    $routes->add('test_throw_not_found', '/test/throw-not-found')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'throwNotFound']);

    $routes->add('test_throw_server_error', '/test/throw-server-error')
        ->controller(['Nytodev\InertiaBundle\Tests\Functional\TestController', 'throwServerError']);
};
